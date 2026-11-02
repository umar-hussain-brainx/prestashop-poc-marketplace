{extends file='page.tpl'}

{block name='page_title'}
  {if $bxmp_collection}{$bxmp_collection.name}{else}{l s='Collection not found' d='Modules.Bxmpcollections.Shop'}{/if}
{/block}

{block name='page_content'}
  {if $bxmp_collection}
    <section id="bxmp-collection" class="bxmp-collection" data-slug="{$bxmp_collection.slug}">
      {if $bxmp_collection.preview}
        <div class="alert alert-warning bxmp-preview">{l s='Preview: this collection is currently %status% and not visible to customers.' sprintf=['%status%' => $bxmp_collection.status] d='Modules.Bxmpcollections.Shop'}</div>
      {/if}
      {if $bxmp_collection.description}<div class="bxmp-collection-description">{$bxmp_collection.description nofilter}</div>{/if}
      {if $bxmp_collection.date_end && $bxmp_collection.status == 'live'}
        <p class="bxmp-collection-until text-muted">{l s='Available until %date%' sprintf=['%date%' => $bxmp_collection.date_end|date_format:'%d/%m/%Y'] d='Modules.Bxmpcollections.Shop'}</p>
      {/if}
      <p class="bxmp-collection-count">{l s='%count% products' sprintf=['%count%' => $bxmp_total] d='Modules.Bxmpcollections.Shop'}</p>
      <section id="products">
        <div id="js-product-list">
          <div class="products row">
            {foreach $products as $product}
              {include file='catalog/_partials/miniatures/product.tpl' product=$product productClasses='col-xs-12 col-sm-6 col-lg-4 col-xl-3'}
            {foreachelse}
              <p class="col-xs-12">{l s='No product in this collection yet.' d='Modules.Bxmpcollections.Shop'}</p>
            {/foreach}
          </div>
        </div>
      </section>
      {if $bxmp_pages}
        <nav class="pagination bxmp-pagination"><ul class="page-list clearfix text-sm-center">
          {foreach $bxmp_pages as $p}<li{if $p.current} class="current"{/if}><a href="{$p.url}" class="{if $p.current}disabled{/if}">{$p.n}</a></li>{/foreach}
        </ul></nav>
      {/if}
    </section>
  {else}
    <section class="bxmp-collection-missing">
      <p>{l s='This collection is not available (it may be over or not started yet).' d='Modules.Bxmpcollections.Shop'}</p>
      <p><a class="btn btn-primary" href="{$bxmp_collections_url}">{l s='See current collections' d='Modules.Bxmpcollections.Shop'}</a></p>
    </section>
  {/if}
{/block}
