(function($) {
	'use strict';

	function nonce() {
		return (window.dmAdmin && dmAdmin.nonce) ? dmAdmin.nonce : '';
	}

	function ajaxUrl() {
		return (window.dmAdmin && dmAdmin.ajaxUrl) ? dmAdmin.ajaxUrl : '';
	}

	function flash($el, message, ok) {
		$el.text(message).css('color', ok ? '#00a32a' : '#d63638');
	}

	// Errors arrive as {code, message, data} on 4xx/5xx responses.
	function failMessage(xhr, fallback) {
		var body = xhr && xhr.responseJSON;
		if (body && body.message) {
			return body.message;
		}
		return fallback;
	}

	// Success = HTTP 2xx without an error `code` in the body.
	function isBodyError(body) {
		return !body || typeof body.code !== 'undefined';
	}

	$(function() {
		var $status = $('#dm-add-status');

		function post(payload) {
			payload._ajax_nonce = nonce();
			return $.post(ajaxUrl(), payload);
		}

		// Add mapping via AJAX (form has a working no-JS fallback action).
		$('#dm-add-form').on('submit', function(e) {
			e.preventDefault();
			var domain = $.trim($('#dm-domain').val());
			var blogId = $.trim($('#dm-blog-id').val());
			if (!domain || !blogId) {
				flash($status, 'Domain and Blog ID are required.', false);
				return;
			}
			flash($status, 'Adding mapping…', true);
			post({
				action: 'dm_rest_create_mapping',
				domain: domain,
				blog_id: blogId,
				make_primary: $('#dm-make-primary').is(':checked') ? 1 : 0
			})
				.done(function() {
					window.location.reload();
				})
				.fail(function(xhr) {
					flash($status, failMessage(xhr, 'Failed to add mapping.'), false);
				});
		});

		// Delete mapping.
		$('#dm-mappings-table').on('click', '.dm-delete-mapping', function(e) {
			e.preventDefault();
			if (!window.confirm('Delete this mapping?')) {
				return;
			}
			post({
				action: 'dm_rest_delete_mapping',
				id: $(this).data('id')
			})
				.done(function() {
					window.location.reload();
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, 'Failed to delete mapping.'));
				});
		});

		// Enable / disable mapping.
		$('#dm-mappings-table').on('click', '.dm-toggle-mapping', function(e) {
			e.preventDefault();
			post({
				action: 'dm_rest_patch_mapping',
				id: $(this).data('id'),
				active: $(this).data('active') ? 1 : 0
			})
				.done(function() {
					window.location.reload();
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, 'Failed to update mapping.'));
				});
		});

		// Set primary domain (syncs wp_blogs.domain).
		$('#dm-mappings-table').on('click', '.dm-set-primary', function(e) {
			e.preventDefault();
			if (!window.confirm('Set this domain as the site\'s primary domain? The site address (wp_blogs.domain) will be updated.')) {
				return;
			}
			post({
				action: 'dm_rest_set_primary',
				id: $(this).data('id')
			})
				.done(function() {
					window.location.reload();
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, 'Failed to set primary domain.'));
				});
		});

		// Verify: first click generates a challenge and shows the publish
		// instructions; the next click completes it (the server performs the
		// external proof — HTTP fetch / DNS TXT lookup).
		$('#dm-mappings-table').on('click', '.dm-verify-mapping', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var id = $btn.data('id');
			var pendingToken = $btn.data('dm-token') || '';

			if (pendingToken) {
				completeVerification(id, pendingToken);
				return;
			}

			post({
				action: 'dm_rest_verify_mapping',
				id: id
			})
				.done(function(resp) {
					if (isBodyError(resp)) {
						alert(resp.message || 'Could not start verification.');
						return;
					}
					$btn.data('dm-token', resp.token);
					var message;
					if (resp.method === 'http') {
						message = 'Publish this challenge on the mapped domain:\n\n' +
							'URL: ' + window.location.protocol + '//' + resp.domain + resp.challenge_path + '\n' +
							'Content: ' + resp.token + '\n\n' +
							'Then click Verify again to check it.';
					} else {
						message = 'Create a DNS TXT record:\n\n' +
							'Name: ' + resp.challenge_path + '\n' +
							'Value: ' + resp.token + '\n\n' +
							'Then click Verify again to check it.';
					}
					alert(message);
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, 'Could not start verification.'));
				});
		});

		function completeVerification(id, token) {
			if (!window.confirm('Check the published challenge now?')) {
				return;
			}
			post({
				action: 'dm_rest_verify_mapping',
				id: id,
				token: token
			})
				.done(function(resp) {
					if (isBodyError(resp)) {
						alert(resp.message || 'Verification failed.');
						return;
					}
					alert('Domain verified.');
					window.location.reload();
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, 'Verification failed.'));
				});
		}
	});
})(jQuery);
