{if $pg.pages > 1 || $pg.total > 0}
<nav class="bxmpv-pager" aria-label="Pagination">
  <span class="bxmpv-pager__info">{$pg.from|intval}–{$pg.to|intval} of {$pg.total|intval}</span>
  {if $pg.pages > 1}
    <span class="bxmpv-pager__links">
      {if $pg.prev_url}<a href="{$pg.prev_url|escape:'html':'UTF-8'}" rel="prev" aria-label="Previous page">‹</a>{/if}
      {foreach $pg.links as $l}
        {if $l.current}<span class="is-current" aria-current="page">{$l.n|intval}</span>{else}<a href="{$l.url|escape:'html':'UTF-8'}">{$l.n|intval}</a>{/if}
      {/foreach}
      {if $pg.next_url}<a href="{$pg.next_url|escape:'html':'UTF-8'}" rel="next" aria-label="Next page">›</a>{/if}
    </span>
  {/if}
</nav>
{/if}
