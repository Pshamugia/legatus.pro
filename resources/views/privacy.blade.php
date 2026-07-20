@extends('layouts.app')

@section('title', 'Privacy Policy · Legatus')
@section('description', 'How Legatus handles business account, catalogue, and customer conversation data.')

@section('body')
<main class="legal-page">
    <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
    <article class="legal-card">
        <span class="eyebrow">Effective 20 July 2026</span>
        <h1>Privacy Policy</h1>
        <p>Legatus provides AI-assisted sales conversations for businesses across website chat, Facebook Messenger, and Instagram Direct. This notice explains the data needed to operate that service.</p>

        <h2>Data we process</h2>
        <ul>
            <li>Business account, team, agent configuration, catalogue, policy, and connected-channel information.</li>
            <li>Customer messages, channel-scoped identifiers, conversation state, feedback, and delivery status.</li>
            <li>Contact details only when a customer explicitly asks to share them for a lead or follow-up.</li>
            <li>Security, reliability, model/tool, token, and latency logs needed to operate and audit the service.</li>
        </ul>

        <h2>How we use data</h2>
        <p>We use data to answer customer questions from verified business sources, recommend products, check approved commercial facts, route uncertain requests to a human, deliver messages, prevent abuse, and improve reliability. Legatus does not sell personal data.</p>

        <h2>Service providers and AI</h2>
        <p>Recent conversation context and relevant business evidence may be sent to OpenAI to generate a response. Meta processes Facebook and Instagram messages under its own terms. Hosting, database, and security providers process only the data needed to provide their services.</p>

        <h2>Retention and control</h2>
        <p>Consented lead contact fields are scheduled for anonymization after 90 days. Conversation history is retained for continuity and the business Inbox until the tenant deletes it or requests deletion, subject to limited security or legal retention. Businesses can disconnect a Meta channel at any time; the encrypted channel credential is then removed from Legatus.</p>

        <h2>Your choices</h2>
        <p>You may request access, correction, export, or deletion. We verify account ownership before acting. See the <a href="{{ route('data-deletion') }}">data deletion instructions</a> or contact <a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a>.</p>

        <h2>Security and limitations</h2>
        <p>Legatus uses tenant isolation, encrypted channel credentials, signed webhooks, access controls, redaction, and human handoff. No system is risk-free, and AI-generated language can be imperfect; price, stock, delivery, discount, and order decisions remain governed by verified business systems and human control.</p>

        <h2>Changes</h2>
        <p>Material changes will be posted here with an updated effective date.</p>
    </article>
</main>
@endsection
