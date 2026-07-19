<?php

namespace Database\Seeders;

use App\Models\AgentRun;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\RecommendationEvent;
use App\Models\Reservation;
use App\Models\ShoppingProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LegatusDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $demoPassword = config('legatus.demo_password') ?: (app()->environment('production') ? Str::password(40) : 'password');
            $owner = User::updateOrCreate(['email' => 'demo@legatus.ai'], ['name' => 'Demo Owner', 'password' => Hash::make($demoPassword)]);
            $operator = User::updateOrCreate(['email' => 'operator@legatus.ai'], ['name' => 'ნინო ოპრატორი', 'password' => Hash::make($demoPassword)]);
            $organization = Organization::updateOrCreate(['slug' => 'legatus-demo'], ['name' => 'Chapter & Co.', 'plan' => 'build-week', 'settings' => ['currency' => 'GEL', 'timezone' => 'Asia/Tbilisi']]);
            $organization->users()->syncWithoutDetaching([$owner->id => ['role' => 'owner'], $operator->id => ['role' => 'agent']]);
            $agent = $organization->agents()->updateOrCreate(['slug' => 'legatus-demo'], [
                'name' => 'Legatus',
                'business_name' => 'Chapter & Co.',
                'industry' => 'books',
                'description' => 'დამოუკიდებელი ონლაინ წიგნის მაღაზია თანამედროვე ლიტერატურის კურირებული არჩევანით.',
                'tone' => 'warm, concise and expert',
                'channels' => ['web'],
                'settings' => ['language' => 'ka', 'handoff_threshold' => .72, 'discount_limit' => 10, 'business_hours' => 'ორშ–პარ 09:00–18:00', 'website' => null, 'delivery_policy' => ['timezone' => 'Asia/Tbilisi', 'local_cities' => ['თბილისი', 'Tbilisi'], 'cutoff' => '18:00', 'local_business_days' => 1, 'regional_min_business_days' => 1, 'regional_max_business_days' => 3, 'source_label' => 'Delivery policy · section 3']],
                'is_active' => true,
            ]);

            $products = [
                ['sku' => 'BK-1001', 'name' => 'Piranesi', 'category' => 'მაგიური რეალიზმი', 'description' => 'იდუმალი, ატმოსფერული და ინტელექტუალური მოგზაურობა უცნაურ სამყაროში.', 'price' => 27.50, 'stock' => 7, 'metadata' => ['author' => 'Susanna Clarke', 'format' => 'Paperback', 'mood' => ['mysterious', 'atmospheric'], 'language' => 'English', 'pages' => 272]],
                ['sku' => 'BK-1002', 'name' => 'Babel', 'category' => 'ფენტეზი', 'description' => 'ენის, ძალაუფლებისა და რევოლუციის თანამედროვე ინტელექტუალური ეპოსი.', 'price' => 38.00, 'stock' => 4, 'metadata' => ['author' => 'R. F. Kuang', 'format' => 'Paperback', 'mood' => ['intense', 'dark academia'], 'language' => 'English', 'pages' => 560]],
                ['sku' => 'BK-1003', 'name' => 'The Invisible Life of Addie LaRue', 'category' => 'მაგიური რეალიზმი', 'description' => 'სიყვარულის, მეხსიერებისა და თავისუფლების თანამედროვე ფაუსტური ისტორია.', 'price' => 34.90, 'stock' => 8, 'metadata' => ['author' => 'V. E. Schwab', 'format' => 'Paperback', 'mood' => ['romantic', 'magical'], 'language' => 'English', 'pages' => 448]],
                ['sku' => 'BK-1004', 'name' => 'The Midnight Library', 'category' => 'თანამედროვე რომანი', 'description' => 'არჩევანებზე, სინანულსა და ცხოვრების შესაძლო ვერსიებზე.', 'price' => 29.90, 'stock' => 0, 'metadata' => ['author' => 'Matt Haig', 'format' => 'Paperback', 'mood' => ['hopeful', 'emotional'], 'language' => 'English', 'pages' => 304]],
                ['sku' => 'BK-1005', 'name' => 'Sea of Tranquility', 'category' => 'სამეცნიერო ფანტასტიკა', 'description' => 'დროის, ხელოვნებისა და ადამიანური კავშირის მოკლე, მედიტაციური რომანი.', 'price' => 31.00, 'stock' => 6, 'metadata' => ['author' => 'Emily St. John Mandel', 'format' => 'Paperback', 'mood' => ['reflective', 'literary'], 'language' => 'English', 'pages' => 272]],
                ['sku' => 'BK-1006', 'name' => 'The Seven Moons of Maali Almeida', 'category' => 'მაგიური რეალიზმი', 'description' => 'შავი იუმორით სავსე პოლიტიკური საიდუმლო და ზებუნებრივი მოგზაურობა.', 'price' => 36.00, 'stock' => 3, 'metadata' => ['author' => 'Shehan Karunatilaka', 'format' => 'Paperback', 'mood' => ['dark', 'satirical'], 'language' => 'English', 'pages' => 400]],
                ['sku' => 'BK-1007', 'name' => 'Convenience Store Woman', 'category' => 'თანამედროვე რომანი', 'description' => 'მახვილგონივრული და მოკლე ამბავი საზოგადოებრივ მოლოდინებსა და ინდივიდუალობაზე.', 'price' => 24.50, 'stock' => 14, 'metadata' => ['author' => 'Sayaka Murata', 'format' => 'Paperback', 'mood' => ['witty', 'unusual'], 'language' => 'English', 'pages' => 176]],
                ['sku' => 'BK-1008', 'name' => 'Before the Coffee Gets Cold', 'category' => 'მაგიური რეალიზმი', 'description' => 'თბილი და ემოციური ისტორიები კაფეზე, სადაც წარსულში დაბრუნება შეიძლება.', 'price' => 26.90, 'stock' => 9, 'metadata' => ['author' => 'Toshikazu Kawaguchi', 'format' => 'Paperback', 'mood' => ['warm', 'emotional'], 'language' => 'English', 'pages' => 224]],
                ['sku' => 'BK-1009', 'name' => 'Tomorrow, and Tomorrow, and Tomorrow', 'category' => 'თანამედროვე რომანი', 'description' => 'ამბიციური რომანი შემოქმედებაზე, მეგობრობასა და სიყვარულზე.', 'price' => 32.90, 'stock' => 12, 'metadata' => ['author' => 'Gabrielle Zevin', 'format' => 'Paperback', 'mood' => ['emotional', 'creative'], 'language' => 'English', 'pages' => 416]],
                ['sku' => 'BK-1010', 'name' => 'Klara and the Sun', 'category' => 'სამეცნიერო ფანტასტიკა', 'description' => 'ხელოვნური ინტელექტის თვალით დანახული სიყვარული, მარტოობა და იმედი.', 'price' => 28.90, 'stock' => 5, 'metadata' => ['author' => 'Kazuo Ishiguro', 'format' => 'Paperback', 'mood' => ['reflective', 'tender'], 'language' => 'English', 'pages' => 320]],
                ['sku' => 'BK-1011', 'name' => 'ოსტატი და მარგარიტა', 'category' => 'კლასიკა', 'description' => 'სატირული, ფილოსოფიური და მისტიკური კლასიკა სიყვარულსა და თავისუფლებაზე.', 'price' => 22.00, 'stock' => 10, 'metadata' => ['author' => 'მიხეილ ბულგაკოვი', 'format' => 'Paperback', 'mood' => ['mysterious', 'satirical'], 'language' => 'ქართული', 'pages' => 480]],
                ['sku' => 'BK-1012', 'name' => 'Cloud Cuckoo Land', 'category' => 'თანამედროვე რომანი', 'description' => 'წიგნების ძალაზე აგებული მრავალშრიანი ისტორია წარსულიდან მომავალამდე.', 'price' => 39.50, 'stock' => 2, 'metadata' => ['author' => 'Anthony Doerr', 'format' => 'Paperback', 'mood' => ['epic', 'hopeful'], 'language' => 'English', 'pages' => 640]],
            ];
            $agent->products()->update(['is_active' => false]);
            foreach ($products as $product) {
                $agent->products()->updateOrCreate(['sku' => $product['sku']], $product + ['is_active' => true]);
            }

            $catalog = $agent->knowledgeSources()->updateOrCreate(['type' => 'csv', 'name' => 'Verified product catalog'], ['url' => null, 'file_path' => null, 'status' => 'ready', 'progress' => 100, 'items_found' => count($products), 'items_created' => count($products), 'items_updated' => 0, 'error' => null, 'content_hash' => hash('sha256', json_encode($products)), 'last_synced_at' => now()->subMinutes(2)]);
            $delivery = $agent->knowledgeSources()->updateOrCreate(['type' => 'pdf', 'name' => 'Delivery & returns policy'], ['url' => null, 'file_path' => null, 'status' => 'ready', 'progress' => 100, 'items_found' => 2, 'items_created' => 2, 'items_updated' => 0, 'error' => null, 'content_hash' => hash('sha256', 'delivery-policy-v2'), 'last_synced_at' => now()->subMinutes(6)]);
            $wholesale = $agent->knowledgeSources()->updateOrCreate(['type' => 'url', 'name' => 'Wholesale policy'], ['url' => null, 'file_path' => null, 'status' => 'ready', 'progress' => 100, 'items_found' => 1, 'items_created' => 1, 'items_updated' => 0, 'error' => null, 'content_hash' => hash('sha256', 'wholesale-policy-v2'), 'last_synced_at' => now()->subMinutes(8)]);
            $agent->products()->where('is_active', true)->get()->each(function ($product) use ($catalog): void {
                $product->update(['metadata' => array_merge($product->metadata ?? [], ['source_id' => $catalog->id])]);
            });
            $this->chunk($catalog, $agent->id, 'catalog', 'Product catalog · live inventory', 'Prices and inventory are authoritative only when read from the products table. Catalog contains 12 active titles and is synchronized before customer-facing answers.', ['section' => 'live inventory']);
            $this->chunk($delivery, $agent->id, 'policy', 'Delivery policy · section 3', 'თბილისში 18:00-მდე დადასტურებული შეკვეთა შესაძლებელია მივიდეს მომდევნო სამუშაო დღეს. რეგიონებში მიწოდება ჩვეულებრივ 1–3 სამუშაო დღეს მოითხოვს. ფასი მისამართის მიხედვით ზუსტდება.', ['section' => 3]);
            $this->chunk($delivery, $agent->id, 'policy', 'Returns policy · section 4', 'დაუბეჭდავი და დაუზიანებელი პროდუქტის დაბრუნება შესაძლებელია მიღებიდან 14 კალენდარული დღის განმავლობაში, შეძენის დამადასტურებელი დოკუმენტით.', ['section' => 4]);
            $this->chunk($wholesale, $agent->id, 'policy', 'Wholesale policy · minimum quantity', 'საბითუმო შეთავაზება იწყება 10 ერთეულიდან. 10%-ზე მაღალი ფასდაკლება საჭიროებს მენეჯერის წერილობით თანხმობას.', ['section' => 'pricing']);

            $mariam = $this->conversation($agent, 'demo-mariam', ['customer_name' => 'მარიამი', 'channel' => 'simulated_instagram', 'status' => 'ai', 'intent' => 'recommendation', 'outcome' => null, 'last_message_at' => now()->subMinutes(3)]);
            $this->messages($mariam, [
                ['role' => 'customer', 'content' => 'ვეძებ „ოსტატი და მარგარიტას“ მსგავს, მაგრამ უფრო თანამედროვე წიგნს. 30 ლარამდე მინდა.'],
                ['role' => 'assistant', 'content' => 'სამი მარაგში არსებული ვარიანტი შევარჩიე 30 ₾-მდე: 1) Piranesi — 27.50 ₾, იდუმალი არქიტექტურა და რეალობის საზღვრებთან თამაში; 2) Before the Coffee Gets Cold — 26.90 ₾, თანამედროვე მაგიური რეალიზმი უფრო თბილი და ემოციური ტონით; 3) Klara and the Sun — 28.90 ₾, თანამედროვე, ინტელექტუალური და ოდნავ უცნაური ხედვა ადამიანობაზე. რომელი მიმართულება გირჩევნიათ — ატმოსფერული საიდუმლო, თბილი მაგია თუ რეფლექსიური სამეცნიერო ფანტასტიკა?', 'confidence' => .94, 'feedback' => 'helpful', 'metadata' => ['intent' => 'recommendation', 'products' => $agent->products()->whereIn('sku', ['BK-1001', 'BK-1008', 'BK-1010'])->get()->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'price' => (float) $product->price, 'stock' => $product->stock])->all(), 'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog', 'updated_at' => now()->subMinutes(2)->toIso8601String()]], 'tools_used' => ['save_shopping_preferences', 'recommend_products', 'check_stock']]],
            ]);
            ShoppingProfile::updateOrCreate(['conversation_id' => $mariam->id], ['preferences' => ['budget' => 30, 'mood' => 'mysterious', 'likes' => ['ოსტატი და მარგარიტა'], 'dislikes' => [], 'occasion' => null, 'recipient' => 'self']]);
            RecommendationEvent::where('conversation_id', $mariam->id)->delete();
            RecommendationEvent::create(['conversation_id' => $mariam->id, 'query' => ['budget' => 30, 'mood' => 'mysterious'], 'ranked_products' => $agent->products()->whereIn('sku', ['BK-1001', 'BK-1008', 'BK-1010'])->get()->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'category' => $product->category, 'description' => $product->description, 'price' => (float) $product->price, 'stock' => $product->stock, 'score' => $product->sku === 'BK-1001' ? 10 : 8, 'matched_signals' => $product->sku === 'BK-1001' ? ['mysterious', 'atmospheric'] : ['modern', 'under_budget'], 'within_budget' => true])->all()]);

            $giorgi = $this->conversation($agent, 'demo-giorgi', ['customer_name' => 'გიორგი', 'channel' => 'simulated_messenger', 'status' => 'human', 'intent' => 'offer', 'priority' => 'high', 'handoff_reason' => 'Requested 18% discount exceeds the 10% autonomous limit.', 'handoff_summary' => 'გიორგის სურს Convenience Store Woman-ის 10 ეგზემპლარი კორპორაციული საჩუქრებისთვის. კატალოგის ჯამია 245 ₾; ითხოვს 18% ფასდაკლებას და გადაწყვეტილება პარასკევამდე სჭირდება.', 'suggested_reply' => 'გამარჯობა გიორგი! 10 წიგნის მოთხოვნა და ვადა ვნახე. 18%-იან ფასდაკლებას მენეჯერის დადასტურება სჭირდება; დღესვე დაგიბრუნდებით საუკეთესო პირობებით.', 'outcome' => 'human_handoff', 'outcome_value' => 245, 'last_message_at' => now()->subMinute()]);
            $this->messages($giorgi, [
                ['role' => 'customer', 'content' => 'კორპორაციული საჩუქრისთვის Convenience Store Woman-ის 10 ცალი მინდა. 18% ფასდაკლებას თუ გამიკეთებთ?'],
                ['role' => 'assistant', 'content' => 'მარაგში 14 ცალია. შეთავაზების გადასაცემად დამიტოვეთ სახელი და საკონტაქტო მონაცემი მხოლოდ იმ შემთხვევაში, თუ მისი შენახვის თანხმობასაც ადასტურებთ.', 'confidence' => .97, 'feedback' => 'unhelpful', 'metadata' => ['intent' => 'lead', 'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog']], 'tools_used' => ['check_stock']]],
                ['role' => 'customer', 'content' => 'გიორგი ვარ, [email redacted]. თანახმა ვარ საკონტაქტო მონაცემის შენახვაზე.', 'metadata' => ['pii_redacted' => true]],
                ['role' => 'assistant', 'content' => '10 ეგზემპლარის კატალოგის ღირებულება 245 ₾-ია. მოთხოვნილი ფასდაკლება ჩემს 10%-იან ლიმიტს აღემატება, ამიტომ მენეჯერს გადავცემ სრული კონტექსტით.', 'confidence' => .98, 'metadata' => ['intent' => 'handoff', 'escalation_reason' => 'Discount approval required', 'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog'], ['label' => 'Wholesale policy · minimum quantity', 'type' => 'policy']], 'tools_used' => ['create_lead', 'check_stock', 'build_offer', 'server_guardrail']]],
            ]);
            Lead::updateOrCreate(['conversation_id' => $giorgi->id], ['agent_id' => $agent->id, 'consent_message_id' => $giorgi->messages()->where('role', 'customer')->latest('id')->value('id'), 'name' => 'გიორგი', 'email' => 'giorgi@example.com', 'phone' => null, 'intent' => 'corporate wholesale · 10 books', 'notes' => 'Customer explicitly consented; discount approval required.', 'consent_at' => now()->subMinutes(2), 'retention_until' => now()->addDays(90), 'status' => 'qualified']);

            $nino = $this->conversation($agent, 'demo-nino', ['customer_name' => 'ნინო', 'channel' => 'web', 'status' => 'ai', 'intent' => 'reservation', 'outcome' => 'pending_reservation', 'outcome_value' => 27.50, 'resolved_at' => null, 'last_message_at' => now()->subMinute()]);
            $this->messages($nino, [
                ['role' => 'customer', 'content' => 'Piranesi შემინახე 15 წუთით, სანამ გადავწყვეტ.'],
                ['role' => 'assistant', 'content' => 'ერთი ეგზემპლარი 15 წუთით დროებით დავარეზერვე. ეს ჯერ შეკვეთა ან გადახდა არ არის და თქვენს დადასტურებას საჭიროებს.', 'confidence' => .99, 'feedback' => 'helpful', 'metadata' => ['intent' => 'reservation', 'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog']], 'tools_used' => ['check_stock', 'reserve_product']]],
            ]);
            Reservation::updateOrCreate(['conversation_id' => $nino->id, 'product_id' => $agent->products()->where('sku', 'BK-1001')->value('id')], ['quantity' => 1, 'status' => 'pending', 'expires_at' => now()->addMinutes(15)]);

            $ana = $this->conversation($agent, 'demo-ana', ['customer_name' => 'ანა', 'channel' => 'web', 'status' => 'ai', 'intent' => 'delivery', 'last_message_at' => now()->subMinutes(22)]);
            $this->messages($ana, [
                ['role' => 'customer', 'content' => 'თუ ახლა შევუკვეთავ, ხვალ თბილისში ჩამომივა?'],
                ['role' => 'assistant', 'content' => 'თუ შეკვეთას სამუშაო დღეს 18:00-მდე დაადასტურებთ, თბილისის პოლიტიკით ყველაზე ადრეული ფანჯარა მომდევნო სამუშაო დღეა; cutoff-ის შემდეგ — მომდევნო ხელმისაწვდომი სამუშაო ფანჯარა. ზუსტი თარიღი მისამართისა და დადასტურების მომენტით გამოითვლება.', 'confidence' => .96, 'metadata' => ['intent' => 'delivery', 'sources' => [['label' => 'Delivery policy · section 3', 'type' => 'policy']], 'tools_used' => ['search_knowledge', 'calculate_delivery']]],
            ]);

            $luka = $this->conversation($agent, 'demo-luka', ['customer_name' => 'ლუკა', 'channel' => 'simulated_instagram', 'status' => 'closed', 'intent' => 'lead', 'outcome' => 'qualified_lead', 'resolved_at' => now()->subHours(2), 'last_message_at' => now()->subHours(2)]);
            $this->messages($luka, [
                ['role' => 'customer', 'content' => '15 წიგნი მჭირდება ღონისძიებისთვის. დამიკავშირდით [phone redacted]-ზე, თანახმა ვარ ნომერი შეინახოთ.', 'metadata' => ['pii_redacted' => true]],
                ['role' => 'assistant', 'content' => 'მადლობა — მოთხოვნა qualified lead-ად შევინახე და გუნდი მითითებულ ნომერზე დაგიკავშირდებათ.', 'confidence' => .97, 'feedback' => 'helpful', 'metadata' => ['intent' => 'lead', 'sources' => [['label' => 'Wholesale policy · minimum quantity', 'type' => 'policy']], 'tools_used' => ['search_knowledge', 'create_lead']]],
            ]);
            Lead::updateOrCreate(['conversation_id' => $luka->id], ['agent_id' => $agent->id, 'consent_message_id' => $luka->messages()->where('role', 'customer')->latest('id')->value('id'), 'name' => 'ლუკა', 'email' => null, 'phone' => '+995 555 12 34 56', 'intent' => 'event wholesale order · 15 books', 'notes' => 'Customer explicitly consented to contact storage.', 'consent_at' => now()->subHours(2), 'retention_until' => now()->addDays(90), 'status' => 'qualified']);

            $salome = $this->conversation($agent, 'demo-salome', ['customer_name' => 'სალომე', 'channel' => 'web', 'status' => 'closed', 'intent' => 'price', 'outcome' => 'resolved_by_ai', 'resolved_at' => now()->subHours(5), 'last_message_at' => now()->subHours(5)]);
            $this->messages($salome, [
                ['role' => 'customer', 'content' => 'Klara and the Sun რა ღირს და მარაგშია?'],
                ['role' => 'assistant', 'content' => 'Klara and the Sun 28.90 ₾ ღირს და ამჟამად მარაგში 5 ეგზემპლარია.', 'confidence' => .99, 'feedback' => 'helpful', 'metadata' => ['intent' => 'price', 'sources' => [['label' => 'Verified product catalog', 'type' => 'catalog']], 'tools_used' => ['search_products', 'check_stock']]],
            ]);

            AgentRun::where('agent_id', $agent->id)->where('response_id', 'like', 'demo-trace-%')->delete();
            foreach ([
                [$mariam, 'demo-trace-1', ['save_shopping_preferences', 'recommend_products', 'check_stock'], 920, 244, 1820],
                [$giorgi, 'demo-trace-2', ['create_lead', 'check_stock', 'build_offer', 'server_guardrail'], 810, 196, 1430],
                [$nino, 'demo-trace-3', ['check_stock', 'reserve_product'], 602, 144, 980],
                [$ana, 'demo-trace-4', ['search_knowledge', 'calculate_delivery'], 544, 131, 890],
                [$luka, 'demo-trace-5', ['search_knowledge', 'create_lead'], 731, 163, 1120],
                [$salome, 'demo-trace-6', ['search_products', 'check_stock'], 488, 112, 760],
            ] as [$conversation, $responseId, $tools, $input, $output, $latency]) {
                AgentRun::create(['agent_id' => $agent->id, 'conversation_id' => $conversation->id, 'provider' => 'simulated', 'model' => 'gpt-5.6-sol · simulated', 'response_id' => $responseId, 'status' => 'completed', 'tools_used' => collect($tools)->map(fn ($name) => ['name' => $name])->all(), 'input_tokens' => $input, 'output_tokens' => $output, 'latency_ms' => $latency]);
            }
        });
    }

    private function conversation($agent, string $visitorId, array $attributes)
    {
        return $agent->conversations()->updateOrCreate(['visitor_id' => $visitorId], array_merge([
            'customer_name' => null,
            'channel' => 'web',
            'status' => 'ai',
            'intent' => null,
            'context' => null,
            'assigned_to' => null,
            'priority' => 'normal',
            'handoff_reason' => null,
            'handoff_summary' => null,
            'suggested_reply' => null,
            'outcome' => null,
            'outcome_value' => 0,
            'resolved_at' => null,
            'openai_response_id' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0,
        ], $attributes));
    }

    private function messages($conversation, array $messages): void
    {
        $conversation->messages()->delete();
        $lastMessageAt = $conversation->last_message_at ?? now();
        $count = count($messages);
        $conversation->messages()->createMany(collect($messages)->values()->map(function (array $message, int $index) use ($lastMessageAt, $count): array {
            $timestamp = $lastMessageAt->copy()->subSeconds(($count - $index - 1) * 30);

            return $message + ['created_at' => $timestamp, 'updated_at' => $timestamp];
        })->all());
    }

    private function chunk($source, int $agentId, string $kind, string $title, string $content, array $metadata): void
    {
        $hash = hash('sha256', $content);
        $source->chunks()->updateOrCreate(['content_hash' => $hash], ['agent_id' => $agentId, 'kind' => $kind, 'title' => $title, 'content' => $content, 'metadata' => $metadata, 'embedding' => null]);
    }
}
