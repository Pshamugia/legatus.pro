@extends('layouts.app')

@section('title', 'Terms of Service · Legatus')
@section('description', 'Terms for using the Legatus AI sales employee platform.')

@section('body')
<main class="legal-page">
    <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
    <article class="legal-card">
        <span class="eyebrow">Effective 20 July 2026</span>
        <h1>Terms of Service</h1>
        <p>These terms govern use of Legatus, an AI-assisted customer-conversation and sales-support platform.</p>

        <h2>Business responsibility</h2>
        <p>The business controls its catalogue, policies, connected accounts, staff access, and approved commercial rules. It is responsible for the accuracy and lawful use of the information it supplies and for reviewing escalated conversations.</p>

        <h2>AI and commercial actions</h2>
        <p>Legatus supports discovery and communication; it does not independently complete payment or a final order. Offers are non-binding, reservations are pending until confirmed, and uncertain or approval-sensitive requests may be handed to a human. The business must review the service before relying on it with customers.</p>

        <h2>Acceptable use</h2>
        <p>You may not use Legatus for unlawful, deceptive, abusive, discriminatory, rights-infringing, security-compromising, or unsolicited messaging. You must hold the rights and permissions needed for connected accounts, catalogues, and customer data.</p>

        <h2>Third-party services</h2>
        <p>OpenAI, Meta, hosting, and commerce systems are independent services with their own availability and terms. Their outages, permission reviews, or platform changes can affect Legatus channels.</p>

        <h2>Availability and liability</h2>
        <p>The service is provided without a guarantee of uninterrupted or error-free operation. To the extent permitted by law, Legatus is not liable for indirect or consequential loss. Nothing here excludes rights or liability that cannot lawfully be excluded.</p>

        <h2>Contact</h2>
        <p>Questions about these terms may be sent to <a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a>.</p>
    </article>
</main>
@endsection
