(function ($) {
	'use strict';

	function postAction(data, onSuccess) {
		data.action = 'asc_admin_action';
		data.nonce = ascAdminData.nonce;

		$.post(ascAdminData.ajaxUrl, data)
			.done(function (res) {
				if (res && res.success) {
					onSuccess(res.data);
				} else {
					alert((res && res.data && res.data.message) ? res.data.message : 'خطا در اجرای درخواست.');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: 'خطای ارتباط با سرور وردپرس.';
				alert(msg);
			});
	}

	$(document).on('click', '#asc-btn-create-key', function () {
		var label = $('#asc-new-key-label').val() || 'AI Agent';
		var scopes = [];
		$('.asc-scope-cb:checked').each(function () {
			scopes.push($(this).val());
		});

		postAction({
			sub_action: 'create_key',
			label: label,
			scopes: scopes
		}, function (data) {
			$('#asc-raw-api-key').val(data.api_key);
			$('#asc-created-key-box').slideDown(200);
		});
	});

	$(document).on('click', '.asc-btn-revoke-key', function () {
		if (!confirm('آیا از لغو و حذف این کلید API مطمئن هستید؟')) {
			return;
		}
		var id = $(this).data('id');
		postAction({ sub_action: 'revoke_key', key_id: id }, function () {
			window.location.reload();
		});
	});

	$(document).on('click', '.asc-btn-rollback', function () {
		if (!confirm('آیا می‌خواهید این تغییر به وضعیت دقیق قبلی بازگردانده شود؟')) {
			return;
		}
		var id = $(this).data('id');
		var $btn = $(this).prop('disabled', true).text('در حال بازگردانی...');
		postAction({ sub_action: 'rollback_snapshot', snapshot_id: id }, function (data) {
			alert(data.message || 'بازگردانی با موفقیت انجام شد.');
			window.location.reload();
		});
	});

	$(document).on('click', '.asc-btn-queue', function () {
		var id = $(this).data('id');
		var decision = $(this).data('decision');
		postAction({
			sub_action: 'queue_decision',
			queue_id: id,
			decision: decision
		}, function () {
			window.location.reload();
		});
	});

	$(document).on('click', '.asc-fill-404', function () {
		$('#asc-redir-source').val($(this).data('path')).focus();
	});

	$(document).on('click', '#asc-btn-save-redirect', function () {
		postAction({
			sub_action: 'save_redirect',
			source_path: $('#asc-redir-source').val(),
			target_url: $('#asc-redir-target').val(),
			status_code: $('#asc-redir-code').val()
		}, function () {
			window.location.reload();
		});
	});

	$(document).on('click', '.asc-btn-del-redirect', function () {
		if (!confirm('این قانون ریدایرکت حذف شود؟')) {
			return;
		}
		postAction({
			sub_action: 'delete_redirect',
			redirect_id: $(this).data('id')
		}, function () {
			window.location.reload();
		});
	});

	$(document).on('click', '#asc-btn-save-snippet', function () {
		postAction({
			sub_action: 'save_snippet',
			title: $('#asc-snippet-title').val(),
			snippet_type: $('#asc-snippet-type').val(),
			code: $('#asc-snippet-code').val(),
			is_active: 1
		}, function () {
			window.location.reload();
		});
	});

	$(document).on('click', '#asc-btn-auto-config-autolinks', function () {
		var $btn = $(this).prop('disabled', true).text('در حال اعمال تنظیمات و ساخت لینک‌های داخلی...');
		postAction({
			sub_action: 'configure_autolinks'
		}, function (data) {
			$('#asc-autolinks-config-result').html(
				'<div style="padding:10px;background:#dcfce7;color:#166534;border-radius:8px;font-weight:700;">✓ ' +
				(data.message || 'تنظیمات بهینه با موفقیت ذخیره شد.') +
				'</div>'
			);
			setTimeout(function () { window.location.reload(); }, 1200);
		});
	});

	$(document).on('click', '#asc-btn-sync-bridge', function () {
		var $btn = $(this).prop('disabled', true).text('در حال تبادل زنده با چت هوش مصنوعی...');
		postAction({
			sub_action: 'sync_cloud_bridge'
		}, function (data) {
			$('#asc-bridge-sync-result').html(
				'<div style="padding:10px;background:#dcfce7;color:#166534;border-radius:8px;font-weight:700;">✓ ' +
				(data.message || 'همگام‌سازی ابری با موفقیت انجام شد.') +
				'</div>'
			);
			$btn.prop('disabled', false).text('همگام‌سازی فوری با چت هوش مصنوعی (Sync Now)');
		});
	});
})(jQuery);
