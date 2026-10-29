/* Scoped, progressive enhancement: editing and POST forms work without JS. */
(function () {
	'use strict';
	var bulk = document.getElementById('adf-bulk-form');
	if (!bulk) {
		return;
	}
	var boxes = Array.from(document.querySelectorAll('input[form="adf-bulk-form"][name="submission_ids[]"]'));
	var all = document.querySelector('[data-adf-select-all]');
	var counter = document.querySelector('[data-adf-selection-count]');
	var operation = bulk.querySelector('[name="operation"]');
	var confirmation = bulk.querySelector('.adf-delete-confirm');
	var confirmBox = bulk.querySelector('[name="confirm_delete"]');
	var apply = bulk.querySelector('[name="apply"]');
	function updateSelection() {
		var count = boxes.filter(function (box) { return box.checked; }).length;
		if (all) {
			all.checked = count > 0 && count === boxes.length;
			all.indeterminate = count > 0 && count < boxes.length;
			all.disabled = boxes.length === 0;
		}
		if (counter) {
			counter.textContent = (counter.dataset.template || '%d selected').replace('%d', String(count));
		}
		if (apply) {
			apply.disabled = count === 0;
		}
	}
	if (all) {
		all.hidden = false;
		all.addEventListener('change', function () {
			boxes.forEach(function (box) { box.checked = all.checked; });
			updateSelection();
		});
	}
	boxes.forEach(function (box) { box.addEventListener('change', updateSelection); });
	function updateOperation() {
		var deleting = operation && operation.value === 'delete';
		if (confirmation) {
			confirmation.hidden = !deleting;
		}
		if (confirmBox) {
			confirmBox.required = Boolean(deleting);
			confirmBox.checked = false;
		}
	}
	if (operation) {
		operation.addEventListener('change', updateOperation);
	}
	document.querySelectorAll('.adf-cancel-edit').forEach(function (button) {
		button.hidden = false;
		button.addEventListener('click', function () {
			var details = button.closest('details');
			button.form.reset();
			details.open = false;
			details.querySelector('summary').focus();
		});
	});
	window.addEventListener('pageshow', updateSelection);
	updateSelection();
	updateOperation();
}());
