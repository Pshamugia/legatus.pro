@extends('layouts.app')

@section('title', 'Data Deletion · Legatus')
@section('description', 'How to disconnect Meta channels and request deletion of Legatus data.')

@section('body')
<main class="legal-page">
    <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
    <article class="legal-card">
        <span class="eyebrow">Privacy control</span>
        <h1>Data deletion instructions</h1>
        <p>You can stop Facebook or Instagram processing immediately by opening <strong>Channels</strong> in Legatus and choosing <strong>Disconnect</strong>. This deletes the encrypted channel credential and stops new channel messages.</p>

        <h2>Request complete deletion</h2>
        <ol>
            <li>Email <a href="mailto:{{ config('legatus.privacy_email') }}?subject=Legatus%20data%20deletion%20request">{{ config('legatus.privacy_email') }}</a> from the address used for your Legatus account.</li>
            <li>Include the workspace name and the Facebook Page or Instagram account name. Do not send passwords, access tokens, or customer messages.</li>
            <li>We will verify ownership, confirm the scope, and provide a tracking reference.</li>
        </ol>
        <p>Verified requests are completed within 30 days unless limited data must be retained for security, fraud prevention, dispute resolution, or another legal obligation. We confirm when deletion is complete.</p>

        <h2>Customer requests</h2>
        <p>If you messaged a business that uses Legatus, identify that business in your request. Legatus will coordinate with the business controlling that conversation data.</p>
    </article>
</main>
@endsection
