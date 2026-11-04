/**
 * Plugin Harmony admin behaviour: select-all and live counter.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var boxes = Array.prototype.slice.call( document.querySelectorAll( '.phm-change-box' ) );
		var all = document.getElementById( 'phm-select-all' );
		var counter = document.getElementById( 'phm-count' );
		var form = document.getElementById( 'phm-apply-form' );

		function updateCount() {
			if ( ! counter ) {
				return;
			}
			var checked = boxes.filter( function ( box ) {
				return box.checked;
			} ).length;
			counter.textContent = checked + ' مورد انتخاب شده از ' + boxes.length;
		}

		if ( all ) {
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = all.checked;
				} );
				updateCount();
			} );
		}

		boxes.forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				if ( all ) {
					all.checked = boxes.every( function ( b ) {
						return b.checked;
					} );
				}
				updateCount();
			} );
		} );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var checked = boxes.filter( function ( box ) {
					return box.checked;
				} ).length;

				if ( ! checked ) {
					event.preventDefault();
					window.alert( 'هیچ موردی انتخاب نشده است.' );
					return;
				}

				var message = 'تعداد ' + checked + ' تنظیم نوشته می‌شود. مقدار فعلی همهٔ گزینه‌ها ذخیره و در تاریخچه قابل بازگردانی است. ادامه می‌دهید؟';
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}

		updateCount();
	} );
} )();
