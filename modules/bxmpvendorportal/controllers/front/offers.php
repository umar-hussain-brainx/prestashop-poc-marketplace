<?php
/**
 * Vendor portal — offers, pricing & inventory: list / search / paginate own offers with master product name + EAN,
 * inline edit (price, discount price + window, quantity, active), add an offer on an existing master product
 * (EAN / name search), bulk stock update, and competitive position per offer (core PriceComparator).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\MarketplaceVendor\Domain\OfferInput;
use BrainX\MarketplaceVendor\Domain\RolePolicy;
use BrainX\MarketplaceVendor\Infrastructure\PortalOfferRepository;
use BrainX\MarketplaceVendor\Infrastructure\PositioningService;

class BxmpvendorportalOffersModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'offers';
    protected string $permission = RolePolicy::OFFERS_VIEW;

    private function repo(): PortalOfferRepository
    {
        return new PortalOfferRepository((int) $this->context->language->id, (int) $this->context->shop->id);
    }

    /** Keeps list filters across PRG redirects. */
    private function listParams(): array
    {
        return array_filter([
            'q' => $this->str('q', 64), 'status' => $this->str('status', 16), 'p' => (int) Tools::getValue('p') ?: null,
        ]);
    }

    protected function processPost(string $action): void
    {
        if (!$this->requirePermission(RolePolicy::OFFERS_EDIT)) {
            return;
        }
        switch ($action) {
            case 'update':
                $this->processUpdate();
                break;
            case 'add':
                $this->processAdd();
                break;
            case 'bulk_stock':
                $this->processBulkStock();
                break;
            default:
                parent::processPost($action);
        }
    }

    private function processUpdate(): void
    {
        $existing = $this->repo()->findOwn($this->idVendor, (int) Tools::getValue('id_offer'));
        if ($existing === null) {
            $this->notFound();

            return;
        }
        $v = OfferInput::validate($_POST);
        if ($v['errors'] !== []) {
            $this->errors = array_merge($this->errors, $v['errors']);
            $this->back($this->listParams() + ['edit' => (int) $existing['id_offer']]);

            return;
        }
        $this->save($existing, $v['data'], 'Offer updated.');
        $this->back($this->listParams());
    }

    private function processAdd(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        $idAttr = (int) Tools::getValue('id_product_attribute');
        $repo = $this->repo();
        if (!$repo->masterExists($idProduct, $idAttr)) {
            $this->errors[] = 'Unknown master product / combination.';
            $this->back(['add' => 1]);

            return;
        }
        if (BxmpOffer::findId($this->idVendor, $idProduct, $idAttr)) {
            $this->errors[] = 'You already have an offer on this product — edit it from the list.';
            $this->back(['q' => (string) $repo->masterEan($idProduct, $idAttr)]);

            return;
        }
        $v = OfferInput::validate($_POST + ['active' => 1]);
        $sku = $this->str('vendor_sku', 64);
        if ($sku !== '' && !Validate::isReference($sku)) {
            $v['errors'][] = 'Invalid vendor SKU.';
        }
        if ($v['errors'] !== []) {
            $this->errors = array_merge($this->errors, $v['errors']);
            $this->back(['add' => 1, 'mq' => $this->str('mq', 64)]);

            return;
        }
        $ean = $repo->masterEan($idProduct, $idAttr);
        $this->save(['id_offer' => 0, 'id_product' => $idProduct, 'id_product_attribute' => $idAttr],
            $v['data'] + ['vendor_sku' => $sku, 'ean13' => ($ean !== null && Validate::isEan13($ean)) ? $ean : ''], 'Offer created.');
        $this->back(['q' => $sku !== '' ? $sku : (string) $ean]);
    }

    /** Upsert through the core repository, scoped to the vendor; hook + log + native stock sync. */
    private function save(array $existing, array $data, string $message): void
    {
        try {
            $payload = $data + [
                'id_vendor' => $this->idVendor,
                'id_product' => (int) $existing['id_product'],
                'id_product_attribute' => (int) $existing['id_product_attribute'],
            ];
            $payload['id_vendor'] = $this->idVendor; // never from the request
            foreach (['discount_price', 'discount_from', 'discount_to'] as $f) {
                $payload[$f] = $payload[$f] ?? '';
            }
            $id = (new OfferRepository())->upsert($payload);
            Hook::exec('actionBxmpOfferUpdated', ['id_offer' => $id, 'data' => $data, 'source' => 'vendor_portal']);
            Logger::channel('vendor')->info('Offer saved from vendor portal', ['id_offer' => $id, 'id_vendor' => $this->idVendor, 'by_customer' => (int) $this->context->customer->id, 'data' => $data]);
            PortalOfferRepository::syncNativeStock((int) $existing['id_product'], (int) $existing['id_product_attribute'], (int) $this->context->shop->id);
            $this->success[] = $message;
        } catch (Throwable $e) {
            $this->errors[] = 'Could not save the offer: ' . $e->getMessage();
        }
    }

    private function processBulkStock(): void
    {
        $parsed = OfferInput::parseBulkStock((string) Tools::getValue('bulk', ''));
        $index = $this->repo()->ownKeyIndex($this->idVendor);
        $updated = 0;
        $unknown = [];
        $touched = [];
        foreach ($parsed['items'] as $key => $qty) {
            $ids = $index[$key] ?? [];
            if ($ids === []) {
                $unknown[] = $key;
                continue;
            }
            foreach ($ids as $idOffer) {
                Db::getInstance()->update('bxmp_offer', ['quantity' => (int) $qty, 'date_upd' => date('Y-m-d H:i:s')],
                    'id_offer = ' . (int) $idOffer . ' AND id_vendor = ' . $this->idVendor);
                Hook::exec('actionBxmpOfferUpdated', ['id_offer' => $idOffer, 'data' => ['quantity' => $qty], 'source' => 'vendor_portal']);
                $touched[$idOffer] = true;
                ++$updated;
            }
        }
        foreach (array_keys($touched) as $idOffer) {
            $row = Db::getInstance()->getRow('SELECT id_product, id_product_attribute FROM `' . _DB_PREFIX_ . 'bxmp_offer` WHERE id_offer = ' . (int) $idOffer);
            if ($row) {
                PortalOfferRepository::syncNativeStock((int) $row['id_product'], (int) $row['id_product_attribute'], (int) $this->context->shop->id);
            }
        }
        Logger::channel('vendor')->info('Bulk stock update', ['id_vendor' => $this->idVendor, 'updated' => $updated, 'unknown' => $unknown]);
        $this->errors = array_merge($this->errors, $parsed['errors']);
        if ($unknown !== []) {
            $this->warning[] = 'Unknown SKU/EAN (not in your offers): ' . implode(', ', array_slice($unknown, 0, 20));
        }
        $this->success[] = sprintf('%d offer(s) updated.', $updated);
        $this->back(['bulk' => 1]);
    }

    protected function renderPage(): void
    {
        $repo = $this->repo();
        $filters = ['q' => $this->str('q', 64), 'status' => $this->str('status', 16)];
        $pg = $this->pagination($repo->countOwn($this->idVendor, $filters));
        $rows = $repo->listOwn($this->idVendor, $filters, $pg['size'], $pg['offset']);
        $positions = (new PositioningService())->positionsFor($this->idVendor, $rows);
        foreach ($rows as &$r) {
            $r['position'] = $positions[(int) $r['id_offer']] ?? null;
            $r['discount_from_input'] = $r['discount_from'] ? str_replace(' ', 'T', substr((string) $r['discount_from'], 0, 16)) : '';
            $r['discount_to_input'] = $r['discount_to'] ? str_replace(' ', 'T', substr((string) $r['discount_to'], 0, 16)) : '';
        }
        unset($r);
        $mq = $this->str('mq', 64);
        $this->context->smarty->assign(['off' => [
            'rows' => $rows,
            'filters' => $filters,
            'pagination' => $this->withLinks($pg, array_filter($filters)),
            'base_url' => $this->url('offers'),
            'add_url' => $this->url('offers', ['add' => 1]),
            'bulk_url' => $this->url('offers', ['bulk' => 1]),
            'list_params' => $this->listParams(),
            'edit_id' => (int) Tools::getValue('edit'),
            'add_mode' => (bool) Tools::getValue('add') || $mq !== '',
            'bulk_mode' => (bool) Tools::getValue('bulk'),
            'mq' => $mq,
            'master_results' => ($mq !== '' && RolePolicy::can($this->role, RolePolicy::OFFERS_EDIT)) ? $repo->searchMaster($this->idVendor, $mq) : [],
            'statuses' => ['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'out_of_stock' => 'Out of stock', 'discount' => 'With discount'],
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/offers.tpl');
    }
}
