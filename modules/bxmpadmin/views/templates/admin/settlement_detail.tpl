<div class="panel" id="bxmp-settlement-detail">
  <div class="panel-heading"><i class="icon-file-text"></i> Settlement #{$s.id_settlement|intval} — {$s.vendor_name|escape:'html':'UTF-8'} — {$s.period_from|escape:'html':'UTF-8'} → {$s.period_to|escape:'html':'UTF-8'} {$status_html nofilter}
    <span class="panel-heading-action"><a class="btn btn-default btn-sm bxmp-export" href="{$export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> Statement CSV</a></span>
  </div>
  <div class="row bxmp-summary">
    <div class="col-md-6">
      <h4>Stored figures</h4>
      <dl class="dl-horizontal">
        <dt>Gross (vendor orders)</dt><dd>{$s.gross|string_format:'%.2f'} €</dd>
        <dt>− Commission</dt><dd>{$s.commission|string_format:'%.2f'} €</dd>
        <dt>+ Adjustments</dt><dd>{$s.adjustments|string_format:'%.2f'} €</dd>
        <dt>= Net payout</dt><dd>{$s.net_payout|string_format:'%.2f'} €</dd>
        <dt>Payout account</dt><dd>{$s.payout_account|default:'–'|escape:'html':'UTF-8'}</dd>
        <dt>Vendor terms (now)</dt><dd>{$s.commission_rate|string_format:'%.2f'} % + {$s.commission_fixed|string_format:'%.2f'} € / line</dd>
      </dl>
    </div>
    <div class="col-md-6">
      <h4>Live recomputation</h4>
      <dl class="dl-horizontal">
        <dt>Gross</dt><dd>{$live.gross|string_format:'%.2f'} € <small class="text-muted">({$live.orders|intval} orders)</small></dd>
        <dt>Commission</dt><dd>{$live.commission|string_format:'%.2f'} €</dd>
        <dt>Adjustments</dt><dd>{$live.adjustments|string_format:'%.2f'} €</dd>
        <dt>Net payout</dt><dd>{$live.net_payout|string_format:'%.2f'} € {if abs($live.net_payout - $s.net_payout) > 0.005}<span class="label label-warning">differs from stored</span>{else}<span class="label label-success">matches</span>{/if}</dd>
        <dt>Already transferred</dt><dd>{$transferred|string_format:'%.2f'} € <small class="text-muted">(payout of orders with a transfer ref)</small></dd>
      </dl>
    </div>
  </div>
  <div class="panel-footer">
    <a class="btn btn-default" href="{$back|escape:'html':'UTF-8'}"><i class="process-icon-back"></i> All settlements</a>
    <a class="btn btn-default" href="{$reconciliation_url|escape:'html':'UTF-8'}"><i class="process-icon-preview"></i> Reconciliation</a>
    <span class="pull-right">{$actions nofilter}</span>
  </div>
</div>
