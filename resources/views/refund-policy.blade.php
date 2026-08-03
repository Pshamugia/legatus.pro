@extends('layouts.app')

@section('title', 'Refund Policy · Legatus')
@section('description', 'Refund and subscription cancellation policy for Legatus.')

@section('body')
<main class="legal-page">
    <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
    <article class="legal-card">
        <span class="eyebrow">Effective 3 August 2026</span>
        <h1>Refund Policy</h1>
        <p>Legatus is a subscription software service offered with a 2-day free trial. A payment method is required when the trial starts, but the first subscription charge is made only after the trial ends unless the subscription is canceled before then.</p>
        <h2>Canceling during the trial</h2>
        <p>You may cancel during the free trial to avoid the first charge. Access continues according to the cancellation terms shown when you cancel.</p>
        <h2>Refund requests</h2>
        <p>If you believe a charge was made in error or the service was materially unavailable, contact us within 14 days of the charge. Include the account email, transaction details, and the reason for the request. We review requests fairly and may approve a full or partial refund where appropriate, subject to applicable law and Paddle’s buyer terms.</p>
        <h2>Renewals and cancellation</h2>
        <p>Subscriptions renew automatically for the selected monthly, six-month, or annual billing period until canceled. Cancel before the next renewal date to prevent the next charge. Cancellation does not automatically refund a charge already processed.</p>
        <h2>How to contact us</h2>
        <p>Email <a href="mailto:{{ config('legatus.privacy_email') }}?subject=Legatus%20refund%20request">{{ config('legatus.privacy_email') }}</a>. Approved refunds are issued to the original payment method through Paddle. Bank processing times may vary.</p>
    </article>
</main>
@endsection
