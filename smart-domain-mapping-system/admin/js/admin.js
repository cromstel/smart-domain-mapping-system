(function($) {
	'use strict';

	// All user-facing strings are provided (translated) by PHP via wp_localize_script.
	function t(key, fallback) {
		var strings = (window.dmAdmin && dmAdmin.i18n) ? dmAdmin.i18n : {};
		return strings[key] || fallback;
	}

	// Replaces %1$s / %2$s (or bare %s) placeholders in order.
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(template).replace(/%(\d+\$)?s/g, function() {
			return args.length ? args.shift() : '';
		});
	}

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
				flash($status, t('required', 'Domain and Blog ID are required.'), false);
				return;
			}
			flash($status, t('adding', 'Adding mapping…'), true);
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
					flash($status, failMessage(xhr, t('failedAdd', 'Failed to add mapping.')), false);
				});
		});

		// Delete mapping.
		$('#dm-mappings-table').on('click', '.dm-delete-mapping', function(e) {
			e.preventDefault();
			if (!window.confirm(t('confirmDelete', 'Delete this mapping?'))) {
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
					alert(failMessage(xhr, t('failedDelete', 'Failed to delete mapping.')));
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
					alert(failMessage(xhr, t('failedUpdate', 'Failed to update mapping.')));
				});
		});

		// Set primary domain (syncs wp_blogs.domain).
		$('#dm-mappings-table').on('click', '.dm-set-primary', function(e) {
			e.preventDefault();
			if (!window.confirm(t('confirmPrimary', "Set this domain as the site's primary domain? The site address (wp_blogs.domain) will be updated."))) {
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
					alert(failMessage(xhr, t('failedPrimary', 'Failed to set primary domain.')));
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
						alert(resp.message || t('couldNotStart', 'Could not start verification.'));
						return;
					}
					$btn.data('dm-token', resp.token);
					var message;
					if (resp.method === 'http') {
						message = fmt(
							t('httpInstructions', "Publish this challenge on the mapped domain:\n\nURL: %1$s\nContent: %2$s\n\nThen click Verify again to check it."),
							window.location.protocol + '//' + resp.domain + resp.challenge_path,
							resp.token
						);
					} else {
						message = fmt(
							t('dnsInstructions', "Create a DNS TXT record:\n\nName: %1$s\nValue: %2$s\n\nThen click Verify again to check it."),
							resp.challenge_path,
							resp.token
						);
					}
					alert(message);
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, t('couldNotStart', 'Could not start verification.')));
				});
		});

		function completeVerification(id, token) {
			if (!window.confirm(t('confirmCheck', 'Check the published challenge now?'))) {
				return;
			}
			post({
				action: 'dm_rest_verify_mapping',
				id: id,
				token: token
			})
				.done(function(resp) {
					if (isBodyError(resp)) {
						alert(resp.message || t('failedVerify', 'Verification failed.'));
						return;
					}
					alert(t('verified', 'Domain verified.'));
					window.location.reload();
				})
				.fail(function(xhr) {
					alert(failMessage(xhr, t('failedVerify', 'Verification failed.')));
				});
		}
	});
})(jQuery);
