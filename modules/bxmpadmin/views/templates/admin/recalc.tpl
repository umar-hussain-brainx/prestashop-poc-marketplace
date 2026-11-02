<form method="post" action="{$action|escape:'html':'UTF-8'}" id="bxmp-recalc-form">
  <div class="alert alert-info">
    The selected vendor orders are not cancelled and not part of an approved/paid settlement. Their line commissions,
    order commission and vendor payout will be recomputed with the vendor's <strong>current</strong> commission terms
    (core CommissionCalculator). Draft settlements of the vendor are refreshed automatically. The change is logged.
  </div>
  {$table nofilter}
  <div class="panel-footer" style="background:none;border:0">
    <a class="btn btn-default" href="{$back|escape:'html':'UTF-8'}"><i class="process-icon-back"></i> Back</a>
    {if $count > 0}
    <button type="submit" name="submitBxmpRecalc" value="1" class="btn btn-primary pull-right"
            onclick="return confirm('Recalculate the commission of the selected vendor orders with the current terms?');">
      <i class="process-icon-refresh"></i> Confirm recalculation
    </button>
    {/if}
  </div>
</form>
