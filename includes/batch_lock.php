<?php
/**
 * batch_lock.php — Soft per-batch "who is processing this" lock for the
 * procurement pipeline (Receiver encoding, Validator pricing).
 *
 * A lock is ACTIVE while `working_at` is within BATCH_LOCK_TTL_MIN minutes.
 * While someone holds a batch, others (admins included) see an "on-going"
 * state instead of opening it. Admins may force-take a batch.
 *
 * All functions are additive and self-contained — they touch only the
 * working_* columns on receiving_batches.
 */
if (!defined('BATCH_LOCK_TTL_MIN')) define('BATCH_LOCK_TTL_MIN', 30);

/** Active lock holder for a batch, or null when free / expired. */
function batch_lock_holder(mysqli $conn, int $batch_id): ?array
{
    $st = $conn->prepare(
        "SELECT working_by, working_username, working_role, working_at,
                TIMESTAMPDIFF(SECOND, working_at, NOW()) AS idle_secs
         FROM receiving_batches
         WHERE id = ? AND working_by IS NOT NULL
           AND working_at >= (NOW() - INTERVAL " . BATCH_LOCK_TTL_MIN . " MINUTE)
         LIMIT 1"
    );
    $st->bind_param("i", $batch_id);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/**
 * Try to acquire/refresh the lock for the current user.
 * Returns true if the user holds it afterwards, false if blocked by an
 * active holder (someone else within the TTL).
 */
function batch_lock_acquire(mysqli $conn, int $batch_id, int $uid, string $uname, string $role): bool
{
    $conn->begin_transaction();
    try {
        $st = $conn->prepare(
            "SELECT working_by,
                    (working_by IS NOT NULL AND working_at >= (NOW() - INTERVAL " . BATCH_LOCK_TTL_MIN . " MINUTE)) AS active
             FROM receiving_batches WHERE id = ? FOR UPDATE"
        );
        $st->bind_param("i", $batch_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) { $conn->rollback(); return false; }

        if (intval($row['active']) === 1 && intval($row['working_by']) !== $uid) {
            $conn->commit();              // held by someone else
            return false;
        }

        // A user works one batch at a time — drop any other lock they hold.
        $r = $conn->prepare("UPDATE receiving_batches SET working_by=NULL, working_username=NULL, working_role=NULL, working_at=NULL WHERE working_by = ? AND id <> ?");
        $r->bind_param("ii", $uid, $batch_id);
        $r->execute();

        $u = $conn->prepare("UPDATE receiving_batches SET working_by=?, working_username=?, working_role=?, working_at=NOW() WHERE id=?");
        $u->bind_param("issi", $uid, $uname, $role, $batch_id);
        $u->execute();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

/** Admin force-take: steal the lock and audit it. */
function batch_lock_force(mysqli $conn, int $batch_id, int $uid, string $uname, string $role): void
{
    $prev = batch_lock_holder($conn, $batch_id);
    $u = $conn->prepare("UPDATE receiving_batches SET working_by=?, working_username=?, working_role=?, working_at=NOW() WHERE id=?");
    $u->bind_param("issi", $uid, $uname, $role, $batch_id);
    $u->execute();

    $reason = "Took over an in-progress batch" . ($prev ? " (was @{$prev['working_username']})" : "") . ".";
    $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, reason) VALUES (?,?,?,?,'lock_override',?)");
    $al->bind_param("iisss", $batch_id, $uid, $uname, $role, $reason);
    $al->execute();
}

/** Release a batch the given user holds (no-op if held by someone else). */
function batch_lock_release(mysqli $conn, int $batch_id, int $uid): void
{
    $u = $conn->prepare("UPDATE receiving_batches SET working_by=NULL, working_username=NULL, working_role=NULL, working_at=NULL WHERE id=? AND working_by=?");
    $u->bind_param("ii", $batch_id, $uid);
    $u->execute();
}
