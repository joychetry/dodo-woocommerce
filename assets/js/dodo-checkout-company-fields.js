/**
 * Dodo Payments Checkout Company Fields
 *
 * Handles the "Buy as Company" checkbox toggle and company name field visibility.
 * Also positions the fields right after the billing last name field.
 *
 * @package Dodo_Payments
 * @since 0.6.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Wait for WooCommerce checkout form to be ready
        $(document.body).on('updated_checkout', function() {
            initCompanyFields();
        });
        
        // Also initialize immediately
        initCompanyFields();

        function initCompanyFields() {
            var $companyFieldsContainer = $('#buy_as_company_fields');
            var $lastNameField = $('#billing_last_name_field');
            
            // Move company fields container right after last name field if it exists
            if ($companyFieldsContainer.length && $lastNameField.length) {
                // Only move if not already positioned correctly
                if ($companyFieldsContainer.prev().attr('id') !== 'billing_last_name_field') {
                    $companyFieldsContainer.insertAfter($lastNameField);
                }
            }

            var $checkbox = $('#buy_as_company_checkbox');
            var $companyField = $('#custom_company_name_field');
            var $taxIdField = $('#dodo_tax_id_field');
            var $taxIdInfo = $('.dodo-tax-id-info');

            // If elements don't exist, return early
            if (!$checkbox.length || !$companyField.length || !$taxIdField.length) {
                return;
            }

            // Toggle a company field's visibility and requirement.
            // Values are kept (not cleared) so toggling off/on doesn't lose what was typed or prefilled.
            function setCompanyField($el, on) {
                $el.css('display', on ? 'block' : 'none').find('input').prop('required', on);
                on ? $el.addClass('validate-required') : $el.removeClass('validate-required');
            }

            // Toggle company fields based on checkbox state (css() on a missing element is a no-op)
            function toggleCompanyField() {
                var on = $checkbox.is(':checked');
                setCompanyField($companyField, on);
                setCompanyField($taxIdField, on);
                $taxIdInfo.css('display', on ? 'block' : 'none');
            }

            // Remove any existing handlers to prevent duplicates
            $checkbox.off('change.companyFields').on('change.companyFields', toggleCompanyField);

            // Trigger on page load to set initial state
            toggleCompanyField();
        }
    });
})(jQuery);

