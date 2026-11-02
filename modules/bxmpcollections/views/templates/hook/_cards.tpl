<div class="bxmp-collection-cards row">
  {foreach $bxmp_collections as $c}
    <div class="col-xs-12 col-md-6 col-lg-4">
      <article class="bxmp-collection-card" data-slug="{$c.slug}">
        <a href="{$c.url}" class="bxmp-collection-card-link">
          <div class="bxmp-collection-thumbs">
            {foreach $c.products as $p}
              {if $p.cover}<img src="{$p.cover.bySize.home_default.url}" alt="{$p.name|truncate:30:'...'}" loading="lazy">{/if}
            {/foreach}
          </div>
          <h3 class="h4 bxmp-collection-name">{$c.name}</h3>
        </a>
        <p class="bxmp-collection-excerpt">{$c.excerpt}</p>
        <a class="btn btn-primary btn-sm" href="{$c.url}">{l s='Discover %count% products' sprintf=['%count%' => $c.count] d='Modules.Bxmpcollections.Shop'}</a>
      </article>
    </div>
  {/foreach}
</div>
