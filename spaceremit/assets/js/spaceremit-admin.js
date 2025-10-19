jQuery(document).ready(function($) {
    'use strict';

    var spaceremitAdmin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Filter transactions
            $('#filter-transactions').on('click', this.filterTransactions);
            
            // Export transactions
            $('#export-transactions').on('click', this.exportTransactions);
            
            // Sync payment status
            $('.sync-payment').on('click', this.syncPayment);
            
            // Auto-refresh every 30 seconds
            setInterval(this.autoRefresh, 30000);
        },

        filterTransactions: function(e) {
            e.preventDefault();
            
            var status = $('#status-filter').val();
            var dateFrom = $('#date-from').val();
            var dateTo = $('#date-to').val();
            
            var data = {
                action: 'spaceremit_get_transactions',
                nonce: spaceremit_admin.nonce,
                status: status,
                date_from: dateFrom,
                date_to: dateTo
            };
            
            $.post(spaceremit_admin.ajax_url, data, function(response) {
                if (response.success) {
                    spaceremitAdmin.updateTransactionsTable(response.data);
                } else {
                    alert('Error filtering transactions: ' + response.data);
                }
            });
        },

        exportTransactions: function(e) {
            e.preventDefault();
            
            var status = $('#status-filter').val();
            var dateFrom = $('#date-from').val();
            var dateTo = $('#date-to').val();
            
            var params = new URLSearchParams({
                action: 'spaceremit_export_transactions',
                nonce: spaceremit_admin.nonce,
                status: status,
                date_from: dateFrom,
                date_to: dateTo
            });
            
            window.open(spaceremit_admin.ajax_url + '?' + params.toString());
        },

        syncPayment: function(e) {
            e.preventDefault();
            
            if (!confirm(spaceremit_admin.strings.confirm_sync)) {
                return;
            }
            
            var $button = $(this);
            var paymentId = $button.data('payment-id');
            var transactionId = $button.data('transaction-id');
            
            $button.prop('disabled', true).text('Syncing...');
            
            var data = {
                action: 'spaceremit_sync_payment',
                nonce: spaceremit_admin.nonce,
                payment_id: paymentId,
                transaction_id: transactionId
            };
            
            $.post(spaceremit_admin.ajax_url, data, function(response) {
                if (response.success) {
                    // Update status in the table
                    var $row = $button.closest('tr');
                    var $statusCell = $row.find('.spaceremit-status');
                    
                    $statusCell.removeClass().addClass('spaceremit-status spaceremit-status-' + response.data.status.toLowerCase());
                    $statusCell.html(response.data.status_label + ' <small>(' + response.data.status + ')</small>');
                    
                    alert(spaceremit_admin.strings.sync_success);
                } else {
                    alert(spaceremit_admin.strings.sync_error + ': ' + response.data);
                }
                
                $button.prop('disabled', false).text('Sync');
            }).fail(function() {
                alert(spaceremit_admin.strings.sync_error);
                $button.prop('disabled', false).text('Sync');
            });
        },

        updateTransactionsTable: function(transactions) {
            var $tbody = $('#spaceremit-transactions-table tbody');
            $tbody.empty();
            
            if (transactions.length === 0) {
                $tbody.append('<tr><td colspan="9">No transactions found.</td></tr>');
                return;
            }
            
            $.each(transactions, function(index, transaction) {
                var row = spaceremitAdmin.buildTransactionRow(transaction);
                $tbody.append(row);
            });
            
            // Re-bind events for new elements
            $('.sync-payment').off('click').on('click', spaceremitAdmin.syncPayment);
        },

        buildTransactionRow: function(transaction) {
            var editUrl = spaceremit_admin.admin_url + 'post.php?post=' + transaction.order_id + '&action=edit';
            var date = new Date(transaction.created_at).toLocaleString();
            
            return '<tr>' +
                '<td>' + transaction.id + '</td>' +
                '<td><a href="' + editUrl + '">#' + transaction.order_id + '</a></td>' +
                '<td><code>' + transaction.spaceremit_payment_id + '</code></td>' +
                '<td>' + transaction.customer_name + '<br><small>' + transaction.customer_email + '</small></td>' +
                '<td>' + transaction.amount + ' ' + transaction.currency + '</td>' +
                '<td><span class="spaceremit-status spaceremit-status-' + transaction.status + '">' +
                    transaction.status.charAt(0).toUpperCase() + transaction.status.slice(1) +
                    ' <small>(' + transaction.status_tag + ')</small></span></td>' +
                '<td>' + transaction.payment_method + '</td>' +
                '<td>' + date + '</td>' +
                '<td><button type="button" class="button button-small sync-payment" ' +
                    'data-payment-id="' + transaction.spaceremit_payment_id + '" ' +
                    'data-transaction-id="' + transaction.id + '">Sync</button></td>' +
                '</tr>';
        },

        autoRefresh: function() {
            // Auto-refresh the page every 30 seconds if no filters are applied
            var hasFilters = $('#status-filter').val() || $('#date-from').val() || $('#date-to').val();
            
            if (!hasFilters) {
                location.reload();
            }
        }
    };

    // Initialize
    spaceremitAdmin.init();
});