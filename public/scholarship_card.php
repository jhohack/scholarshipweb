<?php
// Expects a $scholarship variable to be in scope
$details_link = isset($card_link) ? $card_link : 'scholarship-details.php?id=' . (isset($scholarship['id']) ? $scholarship['id'] : 0);
$btn_text = isset($card_button_text) ? $card_button_text : 'View Details';
?>
<div class="scholarship-card-v2 h-100 w-100">
    <div class="card-banner">
        <?php if (!empty($scholarship['category'])): ?>
            <span class="badge category-badge"><?php echo htmlspecialchars($scholarship['category']); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body d-flex flex-column p-4 pt-3">
        <div class="d-flex align-items-center mb-3">
            <div class="scholarship-provider-logo me-3"><i class="bi bi-building"></i></div>
            <h5 class="card-title fw-bold mb-0 flex-grow-1"><?php echo htmlspecialchars(isset($scholarship['name']) ? $scholarship['name'] : 'Scholarship'); ?></h5>
        </div>
        <p class="card-text text-muted small flex-grow-1"><?php echo htmlspecialchars(substr(isset($scholarship['description']) ? $scholarship['description'] : '', 0, 100)) . (strlen(isset($scholarship['description']) ? $scholarship['description'] : '') > 100 ? '...' : ''); ?></p>
        <div class="row g-2 text-center small mb-3">
            <div class="col-4">
                <?php
                $amt_type = isset($scholarship['amount_type']) ? $scholarship['amount_type'] : 'Peso';
                $amt_val = isset($scholarship['amount']) ? $scholarship['amount'] : 0;
                if ($amt_type === 'Percentage') {
                    $amt_display = number_format($amt_val, 0) . '%';
                } elseif ($amt_type === 'None') {
                    $amt_display = 'None';
                } else {
                    $amt_display = '₱' . number_format($amt_val, 0);
                }
                ?>
                <div class="fw-bold fs-5 text-primary"><?php echo htmlspecialchars($amt_display); ?></div>
                <div class="text-muted">Value</div>
            </div>
            <div class="col-4">
                <div class="fw-bold fs-5 text-danger"><?php echo htmlspecialchars(date("d M", strtotime(isset($scholarship['deadline']) ? $scholarship['deadline'] : 'now'))); ?></div>
                <div class="text-muted">Deadline</div>
            </div>
            <div class="col-4">
                <div class="fw-bold fs-5"><?php echo htmlspecialchars(isset($scholarship['available_slots']) ? $scholarship['available_slots'] : 0); ?></div>
                <div class="text-muted">Slots</div>
            </div>
        </div>
        <a href="<?php echo htmlspecialchars($details_link); ?>" class="btn btn-primary w-100 mt-auto"><?php echo htmlspecialchars($btn_text); ?></a>
    </div>
</div>