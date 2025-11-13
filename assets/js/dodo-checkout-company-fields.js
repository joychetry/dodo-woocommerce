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
            var $taxIdInfo = $('.dodo-tax-id-info');

            // If elements don't exist, return early
            if (!$checkbox.length || !$companyField.length) {
                return;
            }

            // Function to toggle company name field visibility and requirement
            function toggleCompanyField() {
                if ($checkbox.is(':checked')) {
                    $companyField.css('display', 'block').find('input').prop('required', true);
                    // Add required class for styling
                    $companyField.addClass('validate-required');
                    // Show tax ID info text
                    if ($taxIdInfo.length) {
                        $taxIdInfo.css('display', 'block');
                    }
                } else {
                    $companyField.css('display', 'none').find('input').prop('required', false).val('');
                    // Remove required class
                    $companyField.removeClass('validate-required');
                    // Hide tax ID info text
                    if ($taxIdInfo.length) {
                        $taxIdInfo.css('display', 'none');
                    }
                }
            }

            // Remove any existing handlers to prevent duplicates
            $checkbox.off('change.companyFields').on('change.companyFields', toggleCompanyField);

            // Trigger on page load to set initial state
            toggleCompanyField();
        }
    });
})(jQuery);

