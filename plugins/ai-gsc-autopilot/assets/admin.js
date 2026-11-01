(function ($) {
	'use strict';

	function callAjax(payload, onSuccess, onDone) {
		payload.action = 'agsc_admin_action';
		payload.nonce = agscAdminData.nonce;

		$.post(agscAdminData.ajaxUrl, payload)
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
			})
			.always(function () {
				if (onDone) {
					onDone();
				}
			});
	}

	$(document).on('click', '#agsc-btn-sync', function () {
		var $btn = $(this).prop('disabled', true).text('در حال همگام‌سازی...');
		callAjax({ sub_action: 'sync_now' }, function (data) {
			alert(data.message || ('همگام‌سازی انجام شد؛ ' + (data.issues_detected || 0) + ' مورد شناسایی/به‌روزرسانی شد.'));
			window.location.reload();
		}, function () {
			$btn.prop('disabled', false).text('همگام‌سازی فوری با سرچ کنسول');
		});
	});

	$(document).on('click', '#agsc-btn-autopilot', function () {
		if (!confirm('آیا مایلید همگام‌سازی سرچ کنسول، تحلیل مشکلات و اعمال اصلاحات ایمن (همراه با Snapshot پشتیبان) به‌صورت خودکار انجام شود؟')) {
			return;
		}
		var $btn = $(this).prop('disabled', true).text('در حال اجرای اتوپایلوت...');
		callAjax({ sub_action: 'run_autopilot' }, function (data) {
			var fixed = (data.autopilot && data.autopilot.fixed_count) ? data.autopilot.fixed_count : 0;
			alert('اتوپایلوت کامل شد! تعداد مشکلات اصلاح‌شده در این نوبت: ' + fixed);
			window.location.reload();
		}, function () {
			$btn.prop('disabled', false).text('تحلیل و اصلاح خودکار (Autopilot)');
		});
	});

	$(document).on('click', '.agsc-btn-analyze', function () {
		var id = $(this).data('id');
		var $btn = $(this).prop('disabled', true).text('در حال تحلیل...');
		callAjax({ sub_action: 'analyze_issue', issue_id: id }, function () {
			window.location.reload();
		}, function () {
			$btn.prop('disabled', false).text('تحلیل هوشمند');
		});
	});

	$(document).on('click', '.agsc-btn-fix', function () {
		var id = $(this).data('id');
		var $btn = $(this).prop('disabled', true).text('در حال اصلاح...');
		callAjax({ sub_action: 'execute_fix', issue_id: id }, function (data) {
			alert(data.message || 'اصلاحات با موفقیت اعمال شد.');
			window.location.reload();
		}, function () {
			$btn.prop('disabled', false).text('اجرای اصلاح در سایت');
		});
	});

	$(document).on('click', '#agsc-btn-inspect', function () {
		var url = $('#agsc-inspect-input').val();
		var $btn = $(this).prop('disabled', true).text('در حال استعلام...');
		callAjax({ sub_action: 'inspect_url', url: url }, function (data) {
			$('#agsc-inspect-output').show().text(JSON.stringify(data, null, 2));
		}, function () {
			$btn.prop('disabled', false).text('بررسی زنده URL');
		});
	});

	$(document).on('click', '#agsc-btn-test-conn', function () {
		var $btn = $(this).prop('disabled', true).text('در حال بررسی...');
		callAjax({ sub_action: 'test_connection' }, function (data) {
			var list = (data && data.siteEntry) ? data.siteEntry.map(function (s) {
				return s.siteUrl + ' (' + s.permissionLevel + ')';
			}).join(' ، ') : 'اتصال برقرار شد (هیچ Property یافت نشد؛ ایمیل اکانت سرویس را در سرچ کنسول اضافه کنید).';
			$('#agsc-test-conn-result').html('<div style="color:#15803d;font-weight:600;">✓ اتصال موفق! سایت‌های مجاز: ' + list + '</div>');
		}, function () {
			$btn.prop('disabled', false).text('تست اتصال و مشاهده Propertyهای مجاز');
		});
	});
})(jQuery);
