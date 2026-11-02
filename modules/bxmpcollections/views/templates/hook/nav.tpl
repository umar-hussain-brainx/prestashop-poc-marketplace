<nav id="bxmp-collections-nav" class="bxmp-collections-nav" aria-label="{l s='Collections' d='Modules.Bxmpcollections.Shop'}">
  <div class="container">
    <a class="bxmp-nav-title" href="{$bxmp_collections_url}">{l s='Collections' d='Modules.Bxmpcollections.Shop'}</a>
    <ul>
      {foreach $bxmp_nav_collections as $c}
        <li><a href="{$c.url}" data-slug="{$c.slug}"{if $c.current} class="current" aria-current="page"{/if}>{$c.name}</a></li>
      {/foreach}
    </ul>
  </div>
</nav>
