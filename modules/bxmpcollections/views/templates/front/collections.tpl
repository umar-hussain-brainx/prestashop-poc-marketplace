{extends file='page.tpl'}

{block name='page_title'}{l s='Collections' d='Modules.Bxmpcollections.Shop'}{/block}

{block name='page_content'}
  {include file='module:bxmpcollections/views/templates/hook/_cards.tpl' bxmp_collections=$bxmp_collections}
  {if !$bxmp_collections}<p>{l s='No collection is running right now.' d='Modules.Bxmpcollections.Shop'}</p>{/if}
{/block}
