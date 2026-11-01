<?php
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit};
use Illuminate\Support\Facades\{DB, Http};

// Review decisions must never become money decisions.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$gateway->webhook($request);
$work = $gateway->receiptReversals($i, 'pi_return')[0];
check(!$work['needs_review'] && $work['review_status'] === 'cleared',
    'A completed external refund with no credit, pending return or dispute clears itself');
$calls = count(Http::$calls);
check($gateway->invoiceReversalReviews($i)['refund:'.$work['id']] === array_intersect_key($work,
    array_flip(['needs_review', 'review_status', 'review_key'])) && count(Http::$calls) === $calls,
    'The unlocked invoice display agrees with current review state without a provider call');

[$i, $s] = $setup(1200, 2); $s->status = 'failed'; $deliver($s);
Credit::$rows['7:usd']->amount = 0; // Spent fallback credit is still a conflict.
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$gateway->webhook($request);
$work = $gateway->receiptReversals($i, 'pi_return')[0];
check($work['needs_review'], 'A zero pooled balance does not clear a refund against spent receipt credit');
$actor = \App\Models\User::$row;
$actor->role_id = 1; $actor->permissions = ['admin.invoices.view'];
rejects(fn () => $gateway->resolveReceiptReversal($i, 'pi_return', $work['id'], $work['kind'], $work['review_key'], 'Checked in Stripe.'), 'HTTP 403');
$actor->permissions[] = 'admin.invoices.update';
rejects(fn () => $gateway->resolveReceiptReversal($i, 'pi_return', $work['id'], $work['kind'], $work['review_key'], '  '), 'HTTP 422');
rejects(fn () => $gateway->resolveReceiptReversal($i, 'pi_return', $work['id'], $work['kind'], str_repeat('0', 64), 'Checked in Stripe.'), 'HTTP 409');
$moneyBefore = serialize([DB::$tables, Credit::$rows, ExtensionHelper::$payments, $s->posts]);
$auditsBefore = count(Audit::$rows);
$resolve = fn () => $gateway->resolveReceiptReversal($i, 'pi_return', $work['id'], $work['kind'], $work['review_key'], 'Checked spent credit and settled recovery in Stripe.');
$resolve(); $resolve();
$resolved = $gateway->receiptReversals($i, 'pi_return')[0];
$audit = end(Audit::$rows);
check(!$resolved['needs_review'] && $resolved['review_status'] === 'resolved' && $resolved['status'] === 'refunded'
    && $gateway->invoiceReversalReviews($i)['refund:'.$work['id']]['review_status'] === 'resolved'
    && count(Audit::$rows) === $auditsBefore + 1 && $audit['event'] === 'ae_stripe_reversal_resolved'
    && $audit['user_id'] === $actor->id && $audit['new_values']['stripe_id'] === $work['id']
    && str_contains($audit['new_values']['note'], 'spent credit')
    && serialize([DB::$tables, Credit::$rows, ExtensionHelper::$payments, $s->posts]) === $moneyBefore,
    'One required-note audit resolves review without changing canonical state, credit, ledger or provider sends');
DB::$tables['ae_stripe_receipt_returns']['pi_return:excess']['updated_at'] += 300;
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'],
    'Refreshing an allocation retry or notification timestamp does not reopen unchanged financial facts');
$resolve();
$blocked = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:after-resolution', 1);
check($blocked['state'] === 'review', 'Staff resolution cannot release the money-return hold');
// Changed local evidence invalidates the old decision, including changes outside the reversal itself.
DB::$tables['ae_stripe_receipt_returns']['pi_return:excess']['amount_minor'] = 999;
check($gateway->receiptReversals($i, 'pi_return')[0]['needs_review'], 'New allocation evidence reopens a manually resolved case');
rejects($resolve, 'HTTP 409');

// An unsettled sibling is not proof of a clean final refund.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$gateway->webhook($request);
$s->dispute = (object) ['id' => 'du_review', 'payment_intent' => 'pi_return', 'currency' => 'usd', 'amount' => 100, 'status' => 'needs_response'];
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.created'));
check($gateway->receiptReversals($i, 'pi_return')[0]['needs_review'], 'An unresolved dispute keeps the refund under review');
$s->dispute->status = 'won';
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.closed'));
check(!array_filter($gateway->receiptReversals($i, 'pi_return'), fn ($item) => $item['needs_review']),
    'A won dispute automatically clears both itself and an otherwise clean refund');
