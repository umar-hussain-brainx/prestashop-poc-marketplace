<?php
/**
 * Vendor portal — competitive price positioning: every own SKU vs competitors on the identical product/combination
 * (rank, best competitor effective price, delta %, buy-box winner) with the suggested price to become cheapest.
 * "Apply" sets the suggested price as list price (managers / owners).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpvendorportal/classes/BxmpVendorPortalController.php';

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\MarketplaceVendor\Domain\RolePolicy;
use BrainX\MarketplaceVendor\Domain\SuggestedPrice;
use BrainX\MarketplaceVendor\Infrastructure\PortalOfferRepository;
use BrainX\MarketplaceVendor\Infrastructure\PositioningService;

class BxmpvendorportalPositioningModuleFrontController extends BxmpVendorPortalController
{
    protected string $portalPage = 'positioning';
    protected string $permission = RolePolicy::POSITIONING_VIEW;

    private function step(): float
    {
        $s = (float) Configuration::get('BXMPV_PRICE_STEP');

        return $s > 0 ? $s : 0.01;
    }

    /** @return array<int, array> own rows with position + suggestion (step-aware) */
    private function rows(): array
    {
        $repo = new PortalOfferRepository((int) $this->context->language->id, (int) $this->context->shop->id);
        $rows = $repo->listOwn($this->idVendor, ['q' => $this->str('q', 64)], 1000, 0);
        $positions = (new PositioningService())->positionsFor($this->idVendor, $rows);
        $suggester = new SuggestedPrice($this->step());
        foreach ($rows as &$r) {
            $pos = $positions[(int) $r['id_offer']];
            if ($pos['own_price'] !== null) {
                $pos['suggestion'] = $suggester->suggest((float) $pos['own_price'], $pos['best_competitor_price']);
            }
            $r['position'] = $pos;
        }

        return $rows;
    }

    protected function processPost(string $action): void
    {
        if ($action !== 'apply') {
            parent::processPost($action);

            return;
        }
        if (!$this->requirePermission(RolePolicy::OFFERS_EDIT)) {
            return;
        }
        $idOffer = (int) Tools::getValue('id_offer');
        $offer = (new PortalOfferRepository((int) $this->context->language->id, (int) $this->context->shop->id))->findOwn($this->idVendor, $idOffer);
        if ($offer === null) {
            $this->notFound();

            return;
        }
        $price = round((float) Tools::getValue('price'), 2);
        if ($price <= 0) {
            $this->errors[] = 'Invalid price.';
            $this->back();

            return;
        }
        try {
            // the suggestion targets the EFFECTIVE price: the active discount is cleared so the new list price applies
            (new OfferRepository())->upsert([
                'id_vendor' => $this->idVendor, 'id_product' => (int) $offer['id_product'], 'id_product_attribute' => (int) $offer['id_product_attribute'],
                'price' => $price, 'discount_price' => '', 'discount_from' => '', 'discount_to' => '',
            ]);
            Hook::exec('actionBxmpOfferUpdated', ['id_offer' => $idOffer, 'data' => ['price' => $price], 'source' => 'vendor_portal']);
            Logger::channel('vendor')->info('Suggested price applied', ['id_offer' => $idOffer, 'id_vendor' => $this->idVendor, 'old' => (float) $offer['price'], 'new' => $price]);
            $this->success[] = sprintf('Price of %s set to %s.', $offer['product_name'], $this->money($price));
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
        $this->back(array_filter(['filter' => $this->str('filter', 16), 'q' => $this->str('q', 64)]));
    }

    protected function renderPage(): void
    {
        $rows = $this->rows();
        $summary = ['skus' => count($rows), 'buy_box' => 0, 'losing' => 0, 'alone' => 0, 'ineligible' => 0];
        foreach ($rows as $r) {
            $p = $r['position'];
            if ($p['reason'] !== null) {
                ++$summary['ineligible'];
            } elseif ($p['competitors'] === 0) {
                ++$summary['alone'];
            } elseif ($p['buy_box']) {
                ++$summary['buy_box'];
            } else {
                ++$summary['losing'];
            }
        }
        $filter = $this->str('filter', 16);
        if ($filter !== '') {
            $rows = array_values(array_filter($rows, static function ($r) use ($filter) {
                $p = $r['position'];

                return match ($filter) {
                    'losing' => $p['reason'] === null && !$p['buy_box'] && $p['competitors'] > 0,
                    'winning' => $p['buy_box'] && $p['competitors'] > 0,
                    'alone' => $p['reason'] === null && $p['competitors'] === 0,
                    'ineligible' => $p['reason'] !== null,
                    default => true,
                };
            }));
        }
        usort($rows, static fn ($a, $b) => [$a['position']['buy_box'], -($a['position']['delta_pct'] ?? -INF)] <=> [$b['position']['buy_box'], -($b['position']['delta_pct'] ?? -INF)]);
        $this->context->smarty->assign(['pos' => [
            'rows' => $rows,
            'summary' => $summary,
            'filter' => $filter,
            'q' => $this->str('q', 64),
            'step' => $this->step(),
            'base_url' => $this->url('positioning'),
            'filter_urls' => array_combine(['winning', 'losing', 'alone', 'ineligible'], array_map(fn ($f) => $this->url('positioning', ['filter' => $f]), ['winning', 'losing', 'alone', 'ineligible'])),
            'offers_url' => $this->url('offers'),
        ]]);
        $this->setTemplate('module:bxmpvendorportal/views/templates/front/positioning.tpl');
    }
}
