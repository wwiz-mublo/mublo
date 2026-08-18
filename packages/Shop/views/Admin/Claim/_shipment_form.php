<?php $companies = $companies ?? []; ?>
<form class="js-ship-form d-grid gap-2" data-action="<?= htmlspecialchars($action ?? '') ?>">
    <select name="company_id" class="form-select" required><option value="">택배사 선택</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['company_id'] ?>"><?= htmlspecialchars($company['company_name'] ?? '') ?></option><?php endforeach; ?></select>
    <input name="invoice_no" class="form-control" placeholder="송장번호" required>
    <input name="admin_memo" class="form-control" placeholder="메모 (선택)">
    <button class="btn btn-primary"><?= htmlspecialchars($label ?? '송장 등록') ?></button>
</form>
