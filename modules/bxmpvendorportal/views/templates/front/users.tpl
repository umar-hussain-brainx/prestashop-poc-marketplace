{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Users{/block}

{block name='portal_content'}
  <div class="bxmpv-card" data-testid="add-user">
    <div class="bxmpv-card__head"><h2>Add a user</h2></div>
    <form method="post" action="{$usr.base_url|escape:'html':'UTF-8'}" class="bxmpv-filters">
      <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
      <input type="hidden" name="action" value="add">
      <label class="bxmpv-grow">Customer account email <input type="email" name="email" class="form-control" required placeholder="colleague@example.com"></label>
      <label>Role
        <select name="role" class="form-control">
          {foreach $usr.roles as $r}<option value="{$r|escape:'html':'UTF-8'}"{if $r == 'staff'} selected{/if}>{$r|escape:'html':'UTF-8'}</option>{/foreach}
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Add user</button>
    </form>
    <ul class="bxmpv-roles">
      {foreach $usr.role_help as $r => $help}<li><span class="bxmpv-role bxmpv-role--{$r|escape:'html':'UTF-8'}">{$r|escape:'html':'UTF-8'}</span> {$help|escape:'html':'UTF-8'}</li>{/foreach}
    </ul>
    <p class="bxmpv-muted">The person must already have a registered customer account on the shop; they then log in with their own credentials.</p>
  </div>

  <div class="bxmpv-table-wrap">
    <table class="bxmpv-table bxmpv-table--stack" data-testid="users-table">
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
      <tbody>
      {foreach $usr.users as $u}
        <tr data-user="{$u.email|escape:'html':'UTF-8'}" class="{if !$u.active}is-inactive{/if}">
          <td data-label="User"><span class="bxmpv-strong">{$u.firstname|escape:'html':'UTF-8'} {$u.lastname|escape:'html':'UTF-8'}</span><br><small class="bxmpv-muted">{$u.email|escape:'html':'UTF-8'}</small>{if $u.id_customer == $usr.me} <span class="bxmpv-badge bxmpv-badge--muted">you</span>{/if}</td>
          <td data-label="Role"><span class="bxmpv-role bxmpv-role--{$u.role|escape:'html':'UTF-8'}">{$u.role|escape:'html':'UTF-8'}</span></td>
          <td data-label="Status">{if $u.active}<span class="bxmpv-pill bxmpv-pill--delivered">active</span>{else}<span class="bxmpv-pill bxmpv-pill--cancelled">inactive</span>{/if}</td>
          <td class="bxmpv-actions">
            {if $u.id_customer != $usr.me}
              <form method="post" action="{$usr.base_url|escape:'html':'UTF-8'}" class="bxmpv-inline-form">
                <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
                <input type="hidden" name="action" value="update"><input type="hidden" name="id_vendor_user" value="{$u.id_vendor_user|intval}">
                <input type="hidden" name="active" value="{if $u.active}1{else}0{/if}">
                <select name="role" class="form-control" aria-label="Role">{foreach $usr.roles as $r}<option value="{$r|escape:'html':'UTF-8'}"{if $r == $u.role} selected{/if}>{$r|escape:'html':'UTF-8'}</option>{/foreach}</select>
                <button class="btn btn-secondary btn-sm" type="submit">Change role</button>
              </form>
              <form method="post" action="{$usr.base_url|escape:'html':'UTF-8'}">
                <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
                <input type="hidden" name="action" value="update"><input type="hidden" name="id_vendor_user" value="{$u.id_vendor_user|intval}">
                <input type="hidden" name="role" value="{$u.role|escape:'html':'UTF-8'}">
                <input type="hidden" name="active" value="{if $u.active}0{else}1{/if}">
                <button class="btn btn-link btn-sm{if $u.active} bxmpv-danger{/if}" type="submit" data-testid="toggle-user">{if $u.active}Deactivate{else}Reactivate{/if}</button>
              </form>
            {/if}
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  </div>
{/block}
