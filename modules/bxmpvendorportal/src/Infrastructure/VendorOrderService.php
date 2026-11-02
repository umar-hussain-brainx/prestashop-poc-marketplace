<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Infrastructure;

use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceVendor\Domain\OrderWorkflow;

/**
 * Vendor-scoped sub-order reads and status workflow (accept → ship → delivered / cancel).
 * The parent PrestaShop order is never modified; optionally a private customer-service note is attached to it.
 */
final class VendorOrderService
{
    public const HIST_PREFIX = 'HIST-';

    private function db(): \Db
    {
        return \Db::getInstance();
    }

    private function where(int $idVendor, array $f): string
    {
        $w = ['vo.id_vendor = ' . (int) $idVendor];
        if (!empty($f['status']) && in_array($f['status'], \BxmpVendorOrder::STATUSES, true)) {
            $w[] = 'vo.status = \'' . pSQL($f['status']) . '\'';
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $k => $op) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
                $w[] = 'vo.date_add ' . $op . ' \'' . pSQL($f[$k]) . ($k === 'date_to' ? ' 23:59:59' : ' 00:00:00') . '\'';
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '\'%' . pSQL(addcslashes($q, '%_')) . '%\'';
            $w[] = '(vo.reference LIKE ' . $like . ' OR vo.tracking_number LIKE ' . $like . (ctype_digit($q) ? ' OR vo.id_vendor_order = ' . (int) $q . ' OR vo.id_order = ' . (int) $q : '') . ')';
        }

        return implode(' AND ', $w);
    }

    public function search(int $idVendor, array $filters, int $limit = 20, int $offset = 0): array
    {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT vo.*,
                (SELECT COALESCE(SUM(l.quantity),0) FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order_line` l WHERE l.id_vendor_order = vo.id_vendor_order) AS items
            FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo WHERE ' . $this->where($idVendor, $filters) . '
            ORDER BY vo.date_add DESC, vo.id_vendor_order DESC LIMIT ' . max(0, $offset) . ', ' . max(1, min(5000, $limit))) ?: [];
    }

    public function count(int $idVendor, array $filters): int
    {
        return (int) \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` vo WHERE ' . $this->where($idVendor, $filters));
    }

    /** Sub-order with lines, ONLY when owned by the vendor (null otherwise). */
    public function findOwn(int $idVendor, int $idVendorOrder): ?array
    {
        $row = $this->db()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` WHERE id_vendor_order = ' . (int) $idVendorOrder . ' AND id_vendor = ' . (int) $idVendor);
        if (!$row) {
            return null;
        }
        $row['lines'] = $this->db()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order_line` WHERE id_vendor_order = ' . (int) $idVendorOrder . ' ORDER BY id_vendor_order_line') ?: [];
        $row['is_history'] = str_starts_with((string) $row['reference'], self::HIST_PREFIX);
        $row['actions'] = OrderWorkflow::availableActions((string) $row['status']);

        return $row;
    }

    /**
     * Delivery data from the parent PS order (customer name + formatted delivery address). Null for demo history rows
     * or when the parent order does not exist.
     */
    public function parentOrderInfo(array $vendorOrder): ?array
    {
        $idOrder = (int) $vendorOrder['id_order'];
        if ($idOrder <= 0 || !empty($vendorOrder['is_history'])) {
            return null;
        }
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return null;
        }
        $address = new \Address((int) $order->id_address_delivery);
        $customer = new \Customer((int) $order->id_customer);

        return [
            'reference' => $order->reference,
            'date_add' => $order->date_add,
            'customer_name' => trim($customer->firstname . ' ' . $customer->lastname),
            'address_html' => \Validate::isLoadedObject($address) ? \AddressFormat::generateAddress($address, [], '<br>') : '',
            'phone' => \Validate::isLoadedObject($address) ? ($address->phone_mobile ?: $address->phone) : '',
            'country' => \Validate::isLoadedObject($address) ? $address->country : '',
        ];
    }

    /**
     * Applies a workflow action. Concurrency-safe (conditional UPDATE on the previous status) and vendor-scoped.
     *
     * @return array{ok:bool, error:string|null, from:string|null, to:string|null}
     */
    public function apply(int $idVendor, int $idVendorOrder, string $action, array $input, array $actor): array
    {
        $order = $this->findOwn($idVendor, $idVendorOrder);
        if ($order === null) {
            return ['ok' => false, 'error' => 'not_found', 'from' => null, 'to' => null];
        }
        $from = (string) $order['status'];
        $v = OrderWorkflow::validate($action, $from, $input);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error'], 'from' => $from, 'to' => null];
        }
        $set = ['status' => pSQL($v['to']), 'date_upd' => date('Y-m-d H:i:s')];
        if ($action === 'ship') {
            $set['tracking_number'] = pSQL($v['tracking_number']);
            $set['carrier_name'] = pSQL($v['carrier_name']);
        }
        $ok = $this->db()->update('bxmp_vendor_order', $set, 'id_vendor_order = ' . (int) $idVendorOrder
            . ' AND id_vendor = ' . (int) $idVendor . ' AND status = \'' . pSQL($from) . '\'', 1);
        if (!$ok || $this->db()->Affected_Rows() !== 1) {
            return ['ok' => false, 'error' => 'concurrent_update', 'from' => $from, 'to' => null];
        }

        if ($action === 'cancel' && !$order['is_history']) {
            $this->restock($order);
        }
        \Hook::exec('actionBxmpVendorOrderStatusUpdate', [
            'id_vendor_order' => (int) $idVendorOrder,
            'old_status' => $from,
            'new_status' => $v['to'],
            'id_vendor' => $idVendor,
            'id_order' => (int) $order['id_order'],
            'tracking_number' => $v['tracking_number'],
            'carrier_name' => $v['carrier_name'],
            'source' => 'vendor_portal',
            'id_customer' => (int) ($actor['id_customer'] ?? 0),
        ]);
        Logger::channel('vendor')->info(sprintf('Vendor order #%d %s → %s', $idVendorOrder, $from, $v['to']), [
            'id_vendor' => $idVendor, 'action' => $action, 'by_customer' => (int) ($actor['id_customer'] ?? 0),
            'tracking' => $v['tracking_number'], 'carrier' => $v['carrier_name'],
        ]);
        if (!$order['is_history'] && (int) \Configuration::get('BXMPV_ORDER_NOTES')) {
            $this->addParentNote($order, sprintf('[Marketplace] %s: sub-order %s is now "%s"%s.', (string) ($actor['vendor_name'] ?? 'Vendor'),
                (string) $order['reference'], $v['to'],
                $action === 'ship' ? sprintf(' (carrier %s, tracking %s)', $v['carrier_name'], $v['tracking_number']) : ''));
        }

        return ['ok' => true, 'error' => null, 'from' => $from, 'to' => $v['to']];
    }

    /** Gives cancelled quantities back to the vendor offers (real orders only) and re-syncs native stock. */
    private function restock(array $order): void
    {
        $idShop = (int) \Context::getContext()->shop->id;
        foreach ($order['lines'] as $l) {
            if ((int) $l['id_offer'] <= 0) {
                continue;
            }
            $this->db()->execute('UPDATE `' . _DB_PREFIX_ . 'bxmp_offer` SET quantity = quantity + ' . (int) $l['quantity'] . ', date_upd = NOW()
                WHERE id_offer = ' . (int) $l['id_offer'] . ' AND id_vendor = ' . (int) $order['id_vendor']);
            PortalOfferRepository::syncNativeStock((int) $l['id_product'], (int) $l['id_product_attribute'], $idShop);
        }
    }

    /** Private (employee-only) customer-service message on the parent order. Never touches the order itself. */
    private function addParentNote(array $order, string $text): void
    {
        try {
            $psOrder = new \Order((int) $order['id_order']);
            if (!\Validate::isLoadedObject($psOrder)) {
                return;
            }
            $customer = new \Customer((int) $psOrder->id_customer);
            $idThread = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $psOrder->id);
            if (!$idThread) {
                $thread = new \CustomerThread();
                $thread->id_contact = (int) \Db::getInstance()->getValue('SELECT id_contact FROM `' . _DB_PREFIX_ . 'contact` ORDER BY id_contact') ?: 1;
                $thread->id_customer = (int) $psOrder->id_customer;
                $thread->id_shop = (int) $psOrder->id_shop;
                $thread->id_order = (int) $psOrder->id;
                $thread->id_lang = (int) $psOrder->id_lang;
                $thread->email = $customer->email;
                $thread->status = 'open';
                $thread->token = \Tools::passwdGen(12);
                $thread->add();
                $idThread = (int) $thread->id;
            }
            $msg = new \CustomerMessage();
            $msg->id_customer_thread = $idThread;
            $msg->id_employee = 0;
            $msg->message = $text;
            $msg->private = true;
            $msg->add();
        } catch (\Throwable $e) {
            Logger::channel('vendor')->warning('Could not add parent order note: ' . $e->getMessage(), ['id_order' => (int) $order['id_order']]);
        }
    }
}
