<?php

declare(strict_types=1);

namespace BrainX\Csv\Worker;

use BrainX\Csv\Catalog\ProductFactory;
use BrainX\Csv\Config;
use BrainX\Csv\Service\MatchExceptionService;
use BrainX\Csv\Service\StockSync;
use BrainX\Csv\Validation\OfferRowMapper;
use BrainX\Marketplace\Domain\Matching\MasterProduct;
use BrainX\Marketplace\Domain\Matching\MatchingEngine;
use BrainX\Marketplace\Domain\Matching\MatchResult;
use BrainX\Marketplace\Infrastructure\Repository\CatalogRepository;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;

/**
 * One valid offer row → master catalog:
 *  0. vendor_sku previously rejected in the exception queue → skipped;
 *  1. vendor_sku already mapped by an offer of this vendor (same EAN or no EAN) → offer updated (fast path, also
 *     replays manual exception resolutions on the next feed run);
 *  2. core MatchingEngine: matched → offer upsert; ambiguous / conflict → bxmp_match_exception (pending);
 *     new → master product created (BXMPCSV_AUTO_CREATE) then offer, else exception "no_match".
 */
final class OfferRowProcessor
{
    private MatchingEngine $engine;
    private OfferRepository $offers;
    private ProductFactory $factory;
    private MatchExceptionService $exceptions;

    public function __construct(
        private readonly int $idVendor,
        private readonly ?int $idJob = null,
        private readonly array $options = [],
        private readonly StockSync $stock = new StockSync(),
    ) {
        $this->engine = new MatchingEngine(new CatalogRepository(), Config::float('BXMPCSV_TITLE_THRESHOLD') ?: 0.6);
        $this->offers = new OfferRepository();
        $this->factory = new ProductFactory();
        $this->exceptions = new MatchExceptionService();
    }

    public function stock(): StockSync
    {
        return $this->stock;
    }

    private function autoCreate(): bool
    {
        return (bool) ($this->options['auto_create'] ?? Config::bool('BXMPCSV_AUTO_CREATE'));
    }

    /** @param array<string,mixed> $d validated row data */
    public function process(array $d, int $line = 0): RowOutcome
    {
        $sku = (string) $d['vendor_sku'];
        if (empty($this->options['ignore_rejected']) && $this->exceptions->isRejected($this->idVendor, $sku)) {
            return new RowOutcome(RowOutcome::SKIPPED, 'vendor_sku ' . $sku . ' was rejected in the matching exception queue');
        }

        if (empty($this->options['skip_sku_memory'])) {
            $known = \Db::getInstance()->getRow('SELECT o.id_product, o.id_product_attribute, o.ean13 FROM `' . _DB_PREFIX_ . 'bxmp_offer` o
                INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = o.id_product
                WHERE o.id_vendor = ' . $this->idVendor . " AND o.vendor_sku = '" . pSQL($sku) . "' ORDER BY o.id_offer");
            if ($known && ($d['ean'] === null || (string) $known['ean13'] === '' || (string) $known['ean13'] === $d['ean'])) {
                return $this->writeOffer($d, (int) $known['id_product'], (int) $known['id_product_attribute'], RowOutcome::UPDATED, 'vendor_sku already mapped');
            }
        }

        $result = $this->engine->match(OfferRowMapper::toVendorRow($d));

        if ($result->isMatched()) {
            return $this->writeOffer($d, (int) $result->idProduct, $result->idProductAttribute, RowOutcome::UPDATED, $result->reason);
        }

        $isNew = $result->status === MatchResult::NEW;
        // A GTIN is authoritative: title-only candidates that carry a different EAN are other products.
        if (!$isNew && $result->method === 'title' && $d['ean'] !== null && Config::bool('BXMPCSV_EAN_DECIDES_NEW')) {
            $others = array_filter($result->candidates, static fn (MasterProduct $p) => $p->ean13 !== null && $p->ean13 !== '' && $p->ean13 !== $d['ean']);
            $isNew = count($others) === count($result->candidates);
        }
        // Variant rows of a group whose parent product already exists become a new combination.
        $parentId = 0;
        if (!empty($d['parent_reference']) && $d['variant_attributes'] !== [] && ($isNew || $result->method === 'title')) {
            $parentId = $this->factory->findProductByReference((string) $d['parent_reference']);
        }

        if ($parentId) {
            $warnings = [];
            $idAttr = $this->factory->addCombination($parentId, OfferRowMapper::toProductDraft($d), $warnings);

            return $this->writeOffer($d, $parentId, $idAttr, RowOutcome::COMBINATION, 'combination of ' . $d['parent_reference'], $warnings);
        }

        if ($isNew && $this->autoCreate()) {
            if (trim((string) $d['title']) === '') {
                $idEx = $this->exceptions->record($this->idVendor, $this->idJob, $d, $line, $result, 'no_match_missing_title');

                return new RowOutcome(RowOutcome::EXCEPTION, 'no match and no title to create a master product', idException: $idEx);
            }
            $warnings = [];
            $created = $this->factory->create(OfferRowMapper::toProductDraft($d), $warnings);

            return $this->writeOffer($d, $created['id_product'], $created['id_product_attribute'], $created['created_product'] ? RowOutcome::CREATED : RowOutcome::COMBINATION, 'master product created', $warnings);
        }

        $reason = $isNew ? 'no_match_auto_create_disabled' : null;
        $idEx = $this->exceptions->record($this->idVendor, $this->idJob, $d, $line, $result, $reason);

        return new RowOutcome(RowOutcome::EXCEPTION, $result->status . ': ' . ($reason ?? $result->reason) . ($result->candidateIds() ? ' (candidates ' . implode(', ', $result->candidateIds()) . ')' : ''), idException: $idEx);
    }

    /** Upsert the vendor offer on a master product / combination (validates through BxmpOffer). */
    public function writeOffer(array $d, int $idProduct, int $idAttr, string $status, string $message = '', array $warnings = []): RowOutcome
    {
        $data = OfferRowMapper::toOffer($d, $this->idVendor, $idProduct, $idAttr);
        $idOffer = $this->offers->upsert($data);
        \Hook::exec('actionBxmpOfferUpdated', ['id_offer' => $idOffer, 'data' => $data, 'source' => 'csv']);
        $this->stock->touch($idProduct, $idAttr);

        return new RowOutcome($status, $message, $idProduct, $idAttr, $idOffer, 0, $warnings);
    }

    /** Create a master product from a row regardless of matching (exception "create new" action). */
    public function createAndWrite(array $d): RowOutcome
    {
        $warnings = [];
        $created = $this->factory->create(OfferRowMapper::toProductDraft($d), $warnings);

        return $this->writeOffer($d, $created['id_product'], $created['id_product_attribute'], $created['created_product'] ? RowOutcome::CREATED : RowOutcome::COMBINATION, 'master product created', $warnings);
    }
}
