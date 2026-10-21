document.addEventListener('click', function (ev) {
  var btn = ev.target.closest('[data-copy]');
  if (!btn) return;
  var txt = btn.getAttribute('data-copy') || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(function () {
      var orig = btn.textContent;
      btn.textContent = '✓ کپی شد';
      setTimeout(function () { btn.textContent = orig; }, 1400);
    });
  }
});

// Live Fee Preview Simulator on Admin Token page & Transfer forms
document.addEventListener('input', function (ev) {
  var simInput = document.getElementById('sim_amount');
  if (!simInput) return;
  if (!['sim_amount', 'fee_mode', 'fee_bps', 'fee_fixed', 'fee_min', 'fee_max', 'fee_payer_mode', 'fee_burn_share_bps'].includes(ev.target.id)) {
    return;
  }
  var amt = parseFloat(simInput.value || '0') || 0;
  var mode = (document.getElementById('fee_mode') || {}).value || 'hybrid';
  var bps = parseFloat((document.getElementById('fee_bps') || {}).value || '0') || 0;
  var fixed = parseFloat((document.getElementById('fee_fixed') || {}).value || '0') || 0;
  var minF = parseFloat((document.getElementById('fee_min') || {}).value || '0') || 0;
  var maxF = parseFloat((document.getElementById('fee_max') || {}).value || '0') || 0;
  var payer = (document.getElementById('fee_payer_mode') || {}).value || 'sender_extra';
  var burnBps = parseFloat((document.getElementById('fee_burn_share_bps') || {}).value || '0') || 0;

  var fee = 0;
  if (mode === 'fixed') fee = fixed;
  else if (mode === 'percentage') fee = (amt * bps) / 10000;
  else if (mode === 'hybrid') fee = fixed + ((amt * bps) / 10000);

  if (mode !== 'zero') {
    if (minF > 0 && fee < minF) fee = minF;
    if (maxF > 0 && fee > maxF) fee = maxF;
  }
  var senderDebit = payer === 'deduct_from_amount' ? amt : (amt + fee);
  var receiverNet = payer === 'deduct_from_amount' ? Math.max(0, amt - fee) : amt;
  var burned = (fee * burnBps) / 10000;

  var out = document.getElementById('sim_result');
  if (out) {
    out.textContent = 'کارمزد محاسبه‌شده: ' + fee.toFixed(4) +
      ' | کسر از فرستنده: ' + senderDebit.toFixed(4) +
      ' | دریافتی خالص گیرنده: ' + receiverNet.toFixed(4) +
      ' | توکن‌سوزی خودکار: ' + burned.toFixed(4);
  }
});
