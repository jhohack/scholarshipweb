<?php
// Expects a $scholarship variable to be in scope
$details_link = isset($card_link) ? $card_link : 'scholarship-details.php?id=' . (isset($scholarship['id']) ? $scholarship['id'] : 0);
$btn_text = isset($card_button_text) ? $card_button_text : 'View Details';
$deadline_open = strtotime((string) ($scholarship['deadline'] ?? 'now')) >= time();
$capacity_state = $scholarship['capacity_state'] ?? null;
if ($capacity_state === null) {
    if (!$deadline_open) {
        $capacity_state = 'closed';
    } elseif (!empty($scholarship['is_full'])) {
        $capacity_state = 'full';
    } elseif (!empty($scholarship['accepting_new_applicants'])) {
        $capacity_state = 'open';
    } else {
        $capacity_state = 'closed';
    }
}

$state_label = match ($capacity_state) {
    'full' => 'Full',
    'closed' => 'Closed',
    'archived' => 'Archived',
    default => 'Open',
};

$state_class = match ($capacity_state) {
    'full' => 'bg-danger',
    'closed' => 'bg-secondary',
    'archived' => 'bg-dark',
    default => 'bg-success',
};

$slots_total = (int) ($scholarship['available_slots'] ?? 0);
$slots_used = (int) ($scholarship['occupied_count'] ?? ($scholarship['approved_count'] ?? 0));
$slots_remaining = (int) ($scholarship['remaining_slots'] ?? max(0, $slots_total - $slots_used));
?>
<div class="scholarship-card-v2 h-100 w-100">
    <div class="card-banner d-flex align-items-start justify-content-between gap-2">
        <?php if (!empty($scholarship['category'])): ?>
            <span class="badge category-badge"><?php echo htmlspecialchars($scholarship['category']); ?></span>
        <?php endif; ?>
        <span class="badge <?php echo htmlspecialchars($state_class); ?> ms-auto"><?php echo htmlspecialchars($state_label); ?></span>
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
                <div class="fw-bold fs-5"><?php echo htmlspecialchars($slots_used . '/' . $slots_total); ?></div>
                <div class="text-muted">Slots</div>
                <div class="small <?php echo $slots_remaining > 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo $slots_remaining > 0 ? htmlspecialchars($slots_remaining . ' left') : 'Full'; ?>
                </div>
            </div>
        </div>
        <a href="<?php echo htmlspecialchars($details_link); ?>" class="btn btn-primary w-100 mt-auto" data-prefetch="hover"><?php echo htmlspecialchars($btn_text); ?></a>
    </div>
</div>
