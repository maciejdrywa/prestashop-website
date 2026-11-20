{extends file='parent:_partials/form-fields.tpl'}

{block name='form_field_errors'}
  {if !empty($field.errors)}
    {* Powiązanie komunikatu z polem uzupełniamy po renderowaniu formularza, także po AJAX. *}
    <div id="pd-error-{$field.name|escape:'html':'UTF-8'}" data-pd-field-error role="alert">
      {$smarty.block.parent}
    </div>
  {/if}
{/block}
