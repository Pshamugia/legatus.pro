@extends('layouts.app')

@section('title', 'Connect WhatsApp · Legatus')

@section('content')
<main class="container" style="max-width:760px;padding-top:48px;padding-bottom:70px">
    <section class="panel">
        <span class="eyebrow">WhatsApp Business</span>
        <h1>Connect your business number</h1>
        <p>Meta will guide you through selecting or registering a WhatsApp Business Account and phone number. Legatus never sees your Meta password.</p>
        <button class="btn lime" id="whatsapp-signup" type="button">Continue with Meta</button>
        <p id="whatsapp-feedback" aria-live="polite"></p>
        <form id="whatsapp-result" method="POST" action="{{ route('channels.whatsapp.store') }}" hidden>
            @csrf
            <input name="code" type="hidden">
            <input name="waba_id" type="hidden">
            <input name="phone_number_id" type="hidden">
        </form>
    </section>
</main>
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="https://connect.facebook.net/en_US/sdk.js"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(()=>{const button=document.querySelector('#whatsapp-signup'),feedback=document.querySelector('#whatsapp-feedback'),form=document.querySelector('#whatsapp-result');let signup={};window.fbAsyncInit=()=>FB.init({appId:@json($appId),autoLogAppEvents:true,xfbml:true,version:'v25.0'});window.addEventListener('message',event=>{if(event.origin!=='https://www.facebook.com'&&event.origin!=='https://web.facebook.com')return;let data=event.data;try{if(typeof data==='string')data=JSON.parse(data)}catch{return}if(data?.type!=='WA_EMBEDDED_SIGNUP')return;if(data.event==='FINISH'){signup={waba_id:data.data?.waba_id,phone_number_id:data.data?.phone_number_id}}else if(data.event==='ERROR'){feedback.textContent='Meta could not finish the WhatsApp setup.'}});button.addEventListener('click',()=>{if(!window.FB){feedback.textContent='Meta connection could not load. Please refresh and try again.';return}feedback.textContent='Complete the steps in Meta…';FB.login(response=>{const code=response?.authResponse?.code;if(!code||!signup.waba_id||!signup.phone_number_id){feedback.textContent='The setup was not completed. Please try again.';return}form.elements.code.value=code;form.elements.waba_id.value=signup.waba_id;form.elements.phone_number_id.value=signup.phone_number_id;form.submit()},{config_id:@json($configurationId),response_type:'code',override_default_response_type:true,extras:{setup:{},featureType:'whatsapp_business_app_onboarding',sessionInfoVersion:'3'}})})})();
</script>
@endsection
