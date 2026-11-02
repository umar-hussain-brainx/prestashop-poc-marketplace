<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Carrier;

/**
 * Creates / restores / removes the "Marketplace multi-vendor shipping" carrier (carrier-module API):
 * is_module + shipping_external + need_range, external_module_name = bxmpcheckout, no tax rules group, no handling
 * fee, one price range 0 → ∞ at €0 in every zone (the real price comes from getOrderShippingCost), all groups.
 *
 * The carrier is tracked by its id_reference (PS creates a new id_carrier whenever a carrier is edited in the BO).
 */
final class CarrierInstaller
{
    public const CONFIG_REFERENCE = 'BXMPCO_CARRIER_REFERENCE';
    public const NAME = 'Marketplace multi-vendor shipping';

    public function __construct(private readonly string $moduleName)
    {
    }

    public static function currentCarrierId(): int
    {
        $ref = (int) \Configuration::getGlobalValue(self::CONFIG_REFERENCE);
        if ($ref <= 0) {
            return 0;
        }
        $carrier = \Carrier::getCarrierByReference($ref);

        return $carrier instanceof \Carrier && \Validate::isLoadedObject($carrier) && !$carrier->deleted ? (int) $carrier->id : 0;
    }

    public function install(): int
    {
        $id = self::currentCarrierId();
        $carrier = new \Carrier($id ?: null);
        $carrier->name = self::NAME;
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->moduleName;
        $carrier->need_range = true;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->is_free = false;
        $carrier->shipping_method = \Carrier::SHIPPING_METHOD_PRICE;
        $carrier->max_width = $carrier->max_height = $carrier->max_depth = 0;
        $carrier->max_weight = 0;
        $carrier->grade = 0;
        $carrier->url = '';
        foreach (\Language::getLanguages(false) as $lang) {
            $carrier->delay[(int) $lang['id_lang']] = 'Shipped by each vendor, 2-5 days';
        }
        if (!$carrier->save()) {
            return 0;
        }
        if (!$carrier->id_reference) {
            $carrier->id_reference = (int) $carrier->id;
            $carrier->update();
        }
        \Configuration::updateGlobalValue(self::CONFIG_REFERENCE, (int) $carrier->id_reference);

        $this->assignGroupsZonesRanges($carrier);
        $this->copyLogo((int) $carrier->id);

        return (int) $carrier->id;
    }

    public function assignGroupsZonesRanges(\Carrier $carrier): void
    {
        $db = \Db::getInstance();
        $idCarrier = (int) $carrier->id;
        $groups = array_map(static fn ($g) => (int) $g['id_group'], \Group::getGroups((int) \Configuration::get('PS_LANG_DEFAULT')));
        $carrier->setGroups($groups, true);

        $db->delete('carrier_zone', 'id_carrier = ' . $idCarrier);
        $db->delete('delivery', 'id_carrier = ' . $idCarrier);
        $db->delete('range_price', 'id_carrier = ' . $idCarrier);
        $range = new \RangePrice();
        $range->id_carrier = $idCarrier;
        $range->delimiter1 = 0;
        $range->delimiter2 = 10000000;
        $range->add();
        foreach (\Zone::getZones(false) as $zone) {
            $db->insert('carrier_zone', ['id_carrier' => $idCarrier, 'id_zone' => (int) $zone['id_zone']]);
            $db->insert('delivery', ['id_carrier' => $idCarrier, 'id_range_price' => (int) $range->id, 'id_range_weight' => null,
                'id_zone' => (int) $zone['id_zone'], 'price' => 0, 'id_shop' => null, 'id_shop_group' => null], true);
        }
        // no tax on marketplace shipping in the POC (vendor shipping prices are tax excluded and tax-free)
        $db->delete('carrier_tax_rules_group_shop', 'id_carrier = ' . $idCarrier);
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            $db->insert('carrier_tax_rules_group_shop', ['id_carrier' => $idCarrier, 'id_tax_rules_group' => 0, 'id_shop' => (int) $idShop]);
        }
    }

    private function copyLogo(int $idCarrier): void
    {
        $src = _PS_MODULE_DIR_ . $this->moduleName . '/views/img/carrier.jpg';
        if (is_file($src) && defined('_PS_SHIP_IMG_DIR_')) {
            @copy($src, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
        }
    }

    public function uninstall(): void
    {
        $id = self::currentCarrierId();
        if ($id > 0) {
            $carrier = new \Carrier($id);
            $carrier->deleted = true;
            $carrier->active = false;
            $carrier->update();
            if ((int) \Configuration::get('PS_CARRIER_DEFAULT') === $id) {
                foreach (\Carrier::getCarriers((int) \Configuration::get('PS_LANG_DEFAULT'), true, false, false, null, \Carrier::ALL_CARRIERS) as $c) {
                    if ((int) $c['id_carrier'] !== $id) {
                        \Configuration::updateValue('PS_CARRIER_DEFAULT', (int) $c['id_carrier']);
                        break;
                    }
                }
            }
        }
        \Configuration::deleteByName(self::CONFIG_REFERENCE);
    }
}
