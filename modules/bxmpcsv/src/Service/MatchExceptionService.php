<?php

declare(strict_types=1);

namespace BrainX\Csv\Service;

use BrainX\Csv\Worker\OfferRowProcessor;
use BrainX\Csv\Worker\RowOutcome;
use BrainX\Marketplace\Domain\Matching\MatchResult;
use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Matching exception queue (bxmp_match_exception): rows the engine could not match safely (ambiguous / conflict,
 * or no match while auto-create is disabled). One pending exception per (vendor, vendor_sku): later imports
 * refresh it instead of piling up duplicates.
 *
 * Resolution: link() to a candidate (or any) product / combination, createProduct() as a new master product,
 * reject() (the SKU is then skipped by later imports), retry() (re-run the matching, e.g. after a catalog fix).
 * link / createProduct / retry replay the stored row: the vendor offer is written immediately.
 */
final class MatchExceptionService
{
    /** @var array<int, array<string,bool>> */
    private array $rejected = [];

    private function table(): string
    {
        return '`' . _DB_PREFIX_ . 'bxmp_match_exception`';
    }

    public function record(int $idVendor, ?int $idJob, array $row, int $line, MatchResult $result, ?string $reason = null): int
    {
        $sku = (string) ($row['vendor_sku'] ?? '');
        $payload = json_encode(['line' => $line, 'id_import_job' => $idJob, 'row' => $row, 'match' => $result->toArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $fields = [
            'id_vendor' => $idVendor,
            'id_import_job' => $idJob,
            'vendor_sku' => pSQL(mb_substr($sku, 0, 64)),
            'ean13' => $row['ean'] === null ? null : pSQL((string) $row['ean']),
            'brand' => $row['brand'] === null ? null : pSQL(mb_substr((string) $row['brand'], 0, 128)),
            'title' => pSQL(mb_substr((string) ($row['title'] ?? ''), 0, 255)),
            'payload' => pSQL((string) $payload, true),
            'reason' => pSQL(mb_substr($reason ?? ($result->status . ':' . $result->reason), 0, 64)),
            'candidates' => pSQL((string) json_encode($result->candidateIds())),
            'status' => 'pending',
            'date_upd' => date('Y-m-d H:i:s'),
        ];
        $db = \Db::getInstance();
        $existing = (int) $db->getValue('SELECT id_match_exception FROM ' . $this->table() . ' WHERE id_vendor = ' . $idVendor
            . " AND vendor_sku = '" . pSQL($sku) . "' AND status = 'pending' ORDER BY id_match_exception DESC");
        if ($existing) {
            $db->update('bxmp_match_exception', $fields, 'id_match_exception = ' . $existing, 0, true);

            return $existing;
        }
        $fields['date_add'] = $fields['date_upd'];
        $db->insert('bxmp_match_exception', $fields, true);

        return (int) $db->Insert_ID();
    }

    public function isRejected(int $idVendor, string $sku): bool
    {
        if (!isset($this->rejected[$idVendor])) {
            $rows = \Db::getInstance()->executeS('SELECT DISTINCT vendor_sku FROM ' . $this->table() . ' WHERE id_vendor = ' . $idVendor . " AND status = 'rejected'") ?: [];
            $this->rejected[$idVendor] = array_fill_keys(array_map('strval', array_column($rows, 'vendor_sku')), true);
        }

        return isset($this->rejected[$idVendor][$sku]);
    }

    public function find(int $id): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT e.*, v.name AS vendor_name FROM ' . $this->table() . ' e
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = e.id_vendor WHERE e.id_match_exception = ' . $id);
        if (!$row) {
            return null;
        }
        $row['payload_data'] = json_decode((string) $row['payload'], true) ?: [];
        $row['candidate_ids'] = json_decode((string) $row['candidates'], true) ?: [];

        return $row;
    }

    /** @return array<int, array<string,mixed>> pending exceptions (optionally of one vendor) */
    public function pending(?int $idVendor = null, int $limit = 100): array
    {
        return \Db::getInstance()->executeS('SELECT * FROM ' . $this->table() . " WHERE status = 'pending'"
            . ($idVendor ? ' AND id_vendor = ' . $idVendor : '') . ' ORDER BY id_match_exception DESC LIMIT ' . max(1, $limit)) ?: [];
    }

    /**
     * Candidate master products with details for the review screen (product + combination level).
     *
     * @return array<int, array<string,mixed>>
     */
    public function candidates(int $id): array
    {
        $ex = $this->find($id);
        if (!$ex) {
            return [];
        }
        $pairs = [];
        foreach ((array) ($ex['payload_data']['match']['candidates'] ?? []) as $c) {
            $pairs[(int) $c['id_product'] . '-' . (int) ($c['id_product_attribute'] ?? 0)] = [(int) $c['id_product'], (int) ($c['id_product_attribute'] ?? 0)];
        }
        foreach ($ex['candidate_ids'] as $idP) {
            $found = false;
            foreach ($pairs as [$p]) {
                $found = $found || $p === (int) $idP;
            }
            if (!$found) {
                $pairs[(int) $idP . '-0'] = [(int) $idP, 0];
            }
        }
        $idLang = (int) \Context::getContext()->language->id ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $out = [];
        foreach ($pairs as [$idP, $idA]) {
            $p = \Db::getInstance()->getRow('SELECT p.id_product, p.reference, p.mpn, p.ean13, p.price, p.active, pl.name, m.name AS brand
                FROM `' . _DB_PREFIX_ . 'product` p
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . (int) \Context::getContext()->shop->id . '
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer
                WHERE p.id_product = ' . $idP);
            if (!$p) {
                continue;
            }
            $p['id_product_attribute'] = $idA;
            $p['combination'] = '';
            if ($idA) {
                $pa = \Db::getInstance()->getRow('SELECT reference, ean13 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idA);
                $p['reference'] = $pa['reference'] ?? $p['reference'];
                $p['ean13'] = $pa['ean13'] ?? $p['ean13'];
                $names = \Db::getInstance()->executeS('SELECT CONCAT(agl.name, ": ", al.name) AS n FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
                    INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $idLang . '
                    WHERE pac.id_product_attribute = ' . $idA) ?: [];
                $p['combination'] = implode(', ', array_column($names, 'n'));
            }
            $p['offers'] = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_offer` WHERE id_product = ' . $idP . ' AND id_product_attribute = ' . $idA);
            $out[] = $p;
        }

        return $out;
    }

    /** Link the exception row to a master product / combination and write the vendor offer. */
    public function link(int $id, int $idProduct, int $idProductAttribute = 0): RowOutcome
    {
        $ex = $this->requirePending($id, true);
        if (!$idProduct || !\Product::existsInDatabase($idProduct, 'product')) {
            throw new \InvalidArgumentException('Unknown master product #' . $idProduct);
        }
        if ($idProductAttribute && !(int) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idProductAttribute . ' AND id_product = ' . $idProduct)) {
            throw new \InvalidArgumentException('Combination #' . $idProductAttribute . ' does not belong to product #' . $idProduct);
        }
        $proc = $this->processor($ex);
        $outcome = $proc->writeOffer($this->row($ex), $idProduct, $idProductAttribute, RowOutcome::UPDATED, 'linked manually');
        $proc->stock()->flush();
        $this->close($id, 'resolved', $idProduct);
        Logger::channel('csv')->info('Matching exception linked', ['id_match_exception' => $id, 'id_product' => $idProduct, 'id_product_attribute' => $idProductAttribute, 'id_offer' => $outcome->idOffer]);

        return $outcome;
    }

    /** Create a new master product from the exception row and write the vendor offer. */
    public function createProduct(int $id): RowOutcome
    {
        $ex = $this->requirePending($id, true);
        $proc = $this->processor($ex);
        $outcome = $proc->createAndWrite($this->row($ex));
        $proc->stock()->flush();
        $this->close($id, 'resolved', $outcome->idProduct);
        Logger::channel('csv')->info('Matching exception resolved with a new master product', ['id_match_exception' => $id, 'id_product' => $outcome->idProduct, 'id_offer' => $outcome->idOffer]);

        return $outcome;
    }

    public function reject(int $id): void
    {
        $this->requirePending($id, false);
        $this->close($id, 'rejected', null);
        Logger::channel('csv')->info('Matching exception rejected', ['id_match_exception' => $id]);
    }

    /** Re-run the matching for the stored row (pending or rejected exception). */
    public function retry(int $id): RowOutcome
    {
        $ex = $this->requirePending($id, true);
        \Db::getInstance()->update('bxmp_match_exception', ['status' => 'pending', 'date_upd' => date('Y-m-d H:i:s')], 'id_match_exception = ' . $id);
        $payload = $ex['payload_data'];
        $proc = new OfferRowProcessor((int) $ex['id_vendor'], $ex['id_import_job'] ? (int) $ex['id_import_job'] : null, ['ignore_rejected' => true]);
        $outcome = $proc->process($this->row($ex), (int) ($payload['line'] ?? 0));
        $proc->stock()->flush();
        if ($outcome->isOk()) {
            $this->close($id, 'resolved', $outcome->idProduct);
        }
        Logger::channel('csv')->info('Matching exception retried', ['id_match_exception' => $id, 'outcome' => $outcome->status, 'message' => $outcome->message]);

        return $outcome;
    }

    private function processor(array $ex): OfferRowProcessor
    {
        return new OfferRowProcessor((int) $ex['id_vendor'], $ex['id_import_job'] ? (int) $ex['id_import_job'] : null, ['ignore_rejected' => true]);
    }

    private function row(array $ex): array
    {
        $row = $ex['payload_data']['row'] ?? null;
        if (!is_array($row) || !isset($row['vendor_sku'], $row['price'])) {
            throw new \RuntimeException('Exception #' . $ex['id_match_exception'] . ' has no replayable row payload');
        }

        return $row + ['ean' => null, 'reference' => null, 'brand' => null, 'title' => '', 'description' => '', 'category' => null,
            'discount_price' => null, 'discount_from' => null, 'discount_to' => null, 'quantity' => 0, 'images' => [], 'condition' => 'new',
            'variant_attributes' => [], 'parent_reference' => null, 'active' => true];
    }

    private function requirePending(int $id, bool $allowRejected): array
    {
        $ex = $this->find($id);
        if (!$ex) {
            throw new \InvalidArgumentException('Matching exception #' . $id . ' not found');
        }
        if ($ex['status'] !== 'pending' && !($allowRejected && $ex['status'] === 'rejected')) {
            throw new \InvalidArgumentException('Matching exception #' . $id . ' is already ' . $ex['status']);
        }

        return $ex;
    }

    private function close(int $id, string $status, ?int $idProduct): void
    {
        \Db::getInstance()->update('bxmp_match_exception', [
            'status' => $status,
            'id_product_resolved' => $idProduct,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_match_exception = ' . $id, 0, true);
        $this->rejected = [];
    }
}
