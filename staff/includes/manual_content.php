<?php
/**
 * manual_content.php — Shared, role-specific user-manual content.
 *
 * Set $manual_role to 'receiver' | 'validator' | 'price_checker' before including.
 * Rendered by both the full Manual tab (manual.php) and the login pop-up modal,
 * so the guide text lives in exactly one place.
 */
$manual_role = $manual_role ?? 'receiver';

$guides = [
    'receiver' => [
        'title'   => 'Receiver Guide',
        'tag'     => 'Encoding deliveries',
        'accent'  => 'sky',
        'intro'   => 'Your job is to record what physically arrives from a supplier — accurately and completely — then send it for price validation.',
        'steps'   => [
            ['Open a delivery voucher', 'Go to <b>Receive Stock</b>. Pick an <b>available voucher</b> the admin created, or continue your own in-progress batch.'],
            ['Scan and encode each item', 'Scan a barcode (or add a row), then fill in: <b>Description, Category, Quantity, Expiry date</b>, and any <b>Damaged</b> count with notes. The barcode auto-fills the name if it is already known.'],
            ['Save vs. Submit', '<b>Save Items</b> keeps your work without sending it — you can come back later. <b>Submit Batch</b> locks the batch and sends it to the Validator. You cannot edit after submitting.'],
            ['What happens next', 'After you submit, the batch moves to <b>In Review</b>. The Validator checks the prices against the supplier receipt.'],
            ['If a batch is re-opened to you', 'A discrepancy means you must <b>re-count</b>. The quantity fields reset to 0 — type the new count into the <b>Qty/Box</b> field (leave Boxes empty), then Submit again.'],
        ],
        'tips'    => [
            'Every item needs at least one barcode and a category before you can submit.',
            'Tick "With expiry" only if the item has an expiry date — then the date is required.',
            'Enter the simple unit count in <b>Qty/Box</b>; only use <b>Boxes</b> for true case/box quantities.',
        ],
    ],
    'validator' => [
        'title'   => 'Validator Guide',
        'tag'     => 'Price verification',
        'accent'  => 'amber',
        'intro'   => 'Your job is to enter the supplier cost for each received item, so the system can check it against the supplier receipt total — without you seeing that total (blind check).',
        'steps'   => [
            ['Open the validation queue', 'Go to <b>Validation Queue</b>. Pick a batch marked <b>Pending</b>. If someone else is already on it, you will see an "on-going" note.'],
            ['Enter the base price', 'For each item, type the <b>supplier cost (base price)</b>. This is the cost from the supplier — not the selling price.'],
            ['Watch the computed subtotal', 'As you type, the <b>Computed Subtotal</b> updates live. This is the sum of (base price × quantity) for all items.'],
            ['Submit your validation', 'Click <b>Submit Validation</b>. The system compares your computed subtotal to the supplier receipt total automatically.'],
            ['Match or discrepancy', '<b>Match</b> → stock is released and inventory updates automatically. <b>Discrepancy</b> → the admin is notified and the batch is held for review (it may come back to you for re-pricing).'],
        ],
        'tips'    => [
            'Base price = supplier cost. The Admin sets the selling price later, never you.',
            'Quantities are locked — you only enter prices. If a count looks wrong, raise it with the admin.',
            'A "Pending Reprice" batch was sent back — re-check your prices and submit again.',
        ],
    ],
    'price_checker' => [
        'title'   => 'Price Checker Guide',
        'tag'     => 'Pipeline audit',
        'accent'  => 'purple',
        'intro'   => 'Your job is oversight: watch the whole procurement pipeline, spot discrepancies, and keep an eye on price changes and who did what.',
        'steps'   => [
            ['Open Price Checker', 'Go to <b>Price Checker</b>. The <b>pipeline funnel</b> at the top shows how many batches sit at each stage (Encoding → Validating → On Hold → Completed).'],
            ['Review discrepancies', 'Open the <b>Discrepancy Report</b> to see which batches failed their tally and which item caused the mismatch.'],
            ['Check recent price changes', 'The <b>Price Changes</b> list shows every product whose selling price changed — old price, new price, and date.'],
            ['Read the audit feed', 'The <b>Audit Feed</b> is the full trail: who created, encoded, validated, or paid — and when.'],
            ['Re-price when asked', 'If the admin re-opens a batch to you for re-pricing, adjust the prices so the computed subtotal matches the supplier receipt, then submit.'],
        ],
        'tips'    => [
            'You have read access across the pipeline — use it to catch problems early.',
            'On-hold batches are waiting for an admin decision; flag anything unusual.',
            'Per-item amounts are visible to you in reports, but never on the Validator screen.',
        ],
    ],
];

$g = $guides[$manual_role] ?? $guides['receiver'];
$accent = $g['accent'];
?>
<div class="manual-guide" data-role="<?= htmlspecialchars($manual_role) ?>">
    <div class="flex items-center gap-3 mb-5">
        <span class="text-[10px] font-black uppercase tracking-[0.2em] text-<?= $accent ?>-600 bg-<?= $accent ?>-50 border border-<?= $accent ?>-100 px-3 py-1 rounded-full"><?= htmlspecialchars($g['tag']) ?></span>
        <h3 class="serif-title text-2xl font-black text-slate-800"><?= htmlspecialchars($g['title']) ?></h3>
    </div>
    <p class="text-slate-500 font-bold text-sm mb-6 leading-relaxed"><?= htmlspecialchars($g['intro']) ?></p>

    <div class="space-y-3">
        <?php foreach ($g['steps'] as $n => $step): ?>
        <div class="flex gap-4 bg-slate-50 border border-slate-100 rounded-2xl p-4">
            <div class="w-8 h-8 rounded-xl bg-<?= $accent ?>-500 text-white font-black flex items-center justify-center flex-shrink-0"><?= $n + 1 ?></div>
            <div class="min-w-0">
                <p class="font-black text-slate-800 text-sm mb-0.5"><?= htmlspecialchars($step[0]) ?></p>
                <p class="text-slate-500 text-sm leading-relaxed"><?= $step[1] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-6 bg-<?= $accent ?>-50/60 border border-<?= $accent ?>-100 rounded-2xl p-5">
        <p class="text-[10px] font-black uppercase tracking-widest text-<?= $accent ?>-600 mb-2">Quick Tips</p>
        <ul class="space-y-1.5">
            <?php foreach ($g['tips'] as $tip): ?>
            <li class="flex items-start gap-2 text-sm text-slate-600 font-bold">
                <span class="text-<?= $accent ?>-500 mt-0.5 flex-shrink-0">•</span>
                <span><?= $tip ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
