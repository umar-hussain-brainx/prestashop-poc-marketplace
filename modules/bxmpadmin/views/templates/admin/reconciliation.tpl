<div class="panel" id="bxmp-reconciliation">
  <div class="panel-heading"><i class="icon-exchange"></i> Reconciliation summary</div>
  <div class="bxmp-kpis">
    <div class="bxmp-kpi bxmp-kpi-main"><div class="bxmp-kpi-label">Gross (vendor orders)</div><div class="bxmp-kpi-value">{$t.gross|string_format:'%.2f'} €</div><div class="bxmp-kpi-sub">{$t.orders|intval} orders · {$t.cancelled|intval} cancelled</div></div>
    <div class="bxmp-kpi"><div class="bxmp-kpi-label">Commissions</div><div class="bxmp-kpi-value">{$t.commission|string_format:'%.2f'} €</div><div class="bxmp-kpi-sub">marketplace revenue before adjustments</div></div>
    <div class="bxmp-kpi"><div class="bxmp-kpi-label">Payouts</div><div class="bxmp-kpi-value">{$t.payout|string_format:'%.2f'} €</div><div class="bxmp-kpi-sub">gross − commission = {($t.gross - $t.commission)|string_format:'%.2f'} €</div></div>
    <div class="bxmp-kpi"><div class="bxmp-kpi-label">Adjustments</div><div class="bxmp-kpi-value">{$t.adjustments|string_format:'%.2f'} €</div><div class="bxmp-kpi-sub">net due to vendors {$t.net_due|string_format:'%.2f'} €</div></div>
    <div class="bxmp-kpi"><div class="bxmp-kpi-label">Transferred</div><div class="bxmp-kpi-value">{$t.transferred|string_format:'%.2f'} €</div><div class="bxmp-kpi-sub">not transferred {$t.untransferred|string_format:'%.2f'} €</div></div>
    <div class="bxmp-kpi"><div class="bxmp-kpi-label">Issues</div><div class="bxmp-kpi-value"><span class="text-danger">{$counts.error|intval}</span> / <span class="text-warning">{$counts.warning|intval}</span></div><div class="bxmp-kpi-sub">errors / warnings</div></div>
  </div>
  {if $by_code}<p>{foreach $by_code as $code => $n}<span class="label label-default">{$code|escape:'html':'UTF-8'}: {$n|intval}</span> {/foreach}</p>{/if}
  <p class="text-muted small">Checks: products + shipping = total paid; total paid − commission = payout; payment transfer reference present for live orders; cancelled orders not paid out;
    commission consistent with the vendor's current terms; Σ vendor orders = PrestaShop parent order (tax excl.); stored settlements = live recomputation.</p>
</div>
