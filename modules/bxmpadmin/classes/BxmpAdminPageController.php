<?php
/**
 * Base class of the bxmpadmin report pages: request filters (period preset / custom dates / vendor / status),
 * Smarty rendering helpers, generic table + CSV export and flash messages.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'bxmpadmin/vendor/autoload.php';

use BrainX\MarketplaceAdmin\Domain\Period;
use BrainX\MarketplaceAdmin\Export\CsvExporter;

abstract class BxmpAdminPageController extends ModuleAdminController
{
    protected ?Period $period = null;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /** Page body (HTML). */
    abstract protected function renderPage(): string;

    /** Handle POST/GET actions (called from postProcess). */
    protected function handleActions(): void
    {
    }

    public function postProcess()
    {
        try {
            $this->handleActions();
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    public function initContent()
    {
        if (!$this->viewAccess()) {
            $this->errors[] = $this->trans('You do not have permission to view this.', [], 'Admin.Notifications.Error');

            return;
        }
        $this->addCSS(_MODULE_DIR_ . 'bxmpadmin/views/css/admin.css');
        foreach (['bxmp_flash_ok' => 'confirmations', 'bxmp_flash_err' => 'errors'] as $key => $prop) {
            if (!empty($this->context->cookie->{$key})) {
                $this->{$prop}[] = (string) $this->context->cookie->{$key};
                unset($this->context->cookie->{$key});
            }
        }
        try {
            $this->content .= $this->renderPage();
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
            PrestaShopLogger::addLog('bxmpadmin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3);
        }
        $this->context->smarty->assign(['content' => $this->content]);
    }

    // ------------------------------------------------------------------ request helpers

    protected function period(): Period
    {
        return $this->period ??= Period::fromRequest(
            (string) Tools::getValue('period', '30d'),
            (string) Tools::getValue('date_from', '') ?: null,
            (string) Tools::getValue('date_to', '') ?: null
        );
    }

    protected function vendorId(): ?int
    {
        $id = (int) Tools::getValue('id_vendor');

        return $id > 0 ? $id : null;
    }

    /** Current filter params, to keep them in links. */
    protected function filterParams(): array
    {
        $p = $this->period();
        $params = ['period' => $p->preset];
        if ($p->preset === 'custom') {
            $params['date_from'] = $p->from->format('Y-m-d');
            $params['date_to'] = $p->to->format('Y-m-d');
        }
        if ($this->vendorId()) {
            $params['id_vendor'] = $this->vendorId();
        }

        return $params;
    }

    protected function url(array $params = [], ?string $controller = null): string
    {
        return $this->context->link->getAdminLink($controller ?? $this->controller_name, true, [], $params);
    }

    protected function redirectWith(string $message, bool $ok = true, array $params = []): void
    {
        $this->context->cookie->{$ok ? 'bxmp_flash_ok' : 'bxmp_flash_err'} = $message;
        $this->context->cookie->write();
        Tools::redirectAdmin($this->url($params));
    }

    protected function fetch(string $template, array $vars = []): string
    {
        $tpl = $this->context->smarty->createTemplate(_PS_MODULE_DIR_ . 'bxmpadmin/views/templates/admin/' . $template, $this->context->smarty);
        $tpl->assign($vars + ['bxmp_currency' => '€']);

        return $tpl->fetch();
    }

    // ------------------------------------------------------------------ building blocks

    /** Period + vendor (+ extra) filter bar. */
    protected function filterBar(array $extra = [], bool $withVendor = true): string
    {
        $p = $this->period();

        return $this->fetch('_filters.tpl', [
            'action' => $this->context->link->getAdminLink($this->controller_name, false),
            'controller' => $this->controller_name,
            'token' => $this->token,
            'presets' => Period::PRESETS,
            'period_label' => $p->label(),
            'period_days' => $p->days(),
            'preset' => $p->preset,
            'date_from' => $p->from->format('Y-m-d'),
            'date_to' => $p->to->format('Y-m-d'),
            'with_vendor' => $withVendor,
            'vendors' => BxmpVendor::getNames(),
            'id_vendor' => (int) $this->vendorId(),
            'extra' => $extra,
        ]);
    }

    /**
     * Generic report table.
     *
     * @param array<string, array{0:string,1?:string}> $columns key => [label, type money|pct|int|num|text|html|date|status|bool]
     */
    protected function table(string $title, array $columns, array $rows, ?string $exportKey = null, array $footer = [], string $icon = 'icon-table', string $empty = 'No data for this period.'): string
    {
        return $this->fetch('_table.tpl', [
            'title' => $title,
            'icon' => $icon,
            'columns' => $columns,
            'rows' => $rows,
            'footer' => $footer,
            'empty' => $empty,
            'export_url' => $exportKey ? $this->url(['export' => $exportKey] + $this->filterParams() + $this->extraExportParams()) : null,
        ]);
    }

    protected function extraExportParams(): array
    {
        return [];
    }

    /** Streams a CSV if ?export=<key> matches. @param array<string, callable(): array{0: array, 1: iterable}> $exports */
    protected function maybeExport(array $exports): void
    {
        $key = (string) Tools::getValue('export');
        if ($key === '' || !isset($exports[$key])) {
            return;
        }
        [$columns, $rows] = $exports[$key]();
        $labels = [];
        foreach ($columns as $k => $c) {
            $labels[$k] = is_array($c) ? $c[0] : $c;
        }
        $rows = array_map(static function ($r) {
            return array_map(static fn ($v) => is_string($v) ? html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8') : $v, $r);
        }, is_array($rows) ? $rows : iterator_to_array($rows));
        $p = $this->period();
        BrainX\Marketplace\Infrastructure\Logger::channel('admin')->info('Report exported', ['report' => $key, 'period' => $p->label(), 'id_vendor' => $this->vendorId()]);
        CsvExporter::send(sprintf('marketplace-%s-%s_%s.csv', $key, $p->from->format('Ymd'), $p->to->format('Ymd')), $labels, $rows);
    }

    protected function link(string $label, string $href, string $class = ''): string
    {
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . ($class ? ' class="' . $class . '"' : '') . '>' . htmlspecialchars($label) . '</a>';
    }

    protected function badge(string $status): string
    {
        $map = ['draft' => 'default', 'approved' => 'info', 'paid' => 'success', 'pending' => 'warning', 'accepted' => 'info', 'shipped' => 'primary',
            'delivered' => 'success', 'cancelled' => 'danger', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info', 'active' => 'success', 'inactive' => 'danger', 'locked' => 'default'];

        return '<span class="label label-' . ($map[$status] ?? 'default') . '">' . htmlspecialchars($status) . '</span>';
    }
}
