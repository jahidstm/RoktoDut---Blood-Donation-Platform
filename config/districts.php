<?php

/*
|--------------------------------------------------------------------------
| Other spellings of district names
|--------------------------------------------------------------------------
|
| The districts table stores Bangla names only (from public/data/bd_locations.json).
| Messages also use English and older spellings, so the request-text resolver
| (App\Services\RequestText\PlaceResolver) matches these as well. Keys must equal
| districts.name exactly.
|
*/

return [
    'aliases' => [
        'কুমিল্লা' => ['Comilla', 'Cumilla'],
        'ফেনী' => ['Feni'],
        'ব্রাহ্মণবাড়িয়া' => ['Brahmanbaria', 'B.Baria'],
        'রাঙ্গামাটি' => ['Rangamati', 'রাঙামাটি'],
        'নোয়াখালী' => ['Noakhali'],
        'চাঁদপুর' => ['Chandpur'],
        'লক্ষ্মীপুর' => ['Lakshmipur', 'Laxmipur'],
        'চট্টগ্রাম' => ['Chittagong', 'Chattogram', 'Ctg', 'চিটাগাং'],
        'কক্সবাজার' => ["Cox's Bazar", 'Coxs Bazar', 'Coxsbazar'],
        'খাগড়াছড়ি' => ['Khagrachhari', 'Khagrachari'],
        'বান্দরবান' => ['Bandarban'],
        'সিরাজগঞ্জ' => ['Sirajganj'],
        'পাবনা' => ['Pabna'],
        'বগুড়া' => ['Bogura', 'Bogra'],
        'রাজশাহী' => ['Rajshahi'],
        'নাটোর' => ['Natore'],
        'জয়পুরহাট' => ['Joypurhat', 'Jaipurhat'],
        'চাঁপাইনবাবগঞ্জ' => ['Chapainawabganj', 'Chapai Nawabganj'],
        'নওগাঁ' => ['Naogaon'],
        'যশোর' => ['Jessore', 'Jashore'],
        'সাতক্ষীরা' => ['Satkhira'],
        'মেহেরপুর' => ['Meherpur'],
        'নড়াইল' => ['Narail'],
        'চুয়াডাঙ্গা' => ['Chuadanga'],
        'কুষ্টিয়া' => ['Kushtia'],
        'মাগুরা' => ['Magura'],
        'খুলনা' => ['Khulna'],
        'বাগেরহাট' => ['Bagerhat'],
        'ঝিনাইদহ' => ['Jhenaidah', 'Jhenidah'],
        'ঝালকাঠি' => ['Jhalokati', 'Jhalakathi', 'Jhalokathi'],
        'পটুয়াখালী' => ['Patuakhali'],
        'পিরোজপুর' => ['Pirojpur'],
        'বরিশাল' => ['Barisal', 'Barishal'],
        'ভোলা' => ['Bhola'],
        'বরগুনা' => ['Barguna'],
        'সিলেট' => ['Sylhet'],
        'মৌলভীবাজার' => ['Moulvibazar', 'Maulvibazar'],
        'হবিগঞ্জ' => ['Habiganj'],
        'সুনামগঞ্জ' => ['Sunamganj'],
        'নরসিংদী' => ['Narsingdi'],
        'গাজীপুর' => ['Gazipur'],
        'শরীয়তপুর' => ['Shariatpur'],
        'নারায়ণগঞ্জ' => ['Narayanganj'],
        'টাঙ্গাইল' => ['Tangail'],
        'কিশোরগঞ্জ' => ['Kishoreganj', 'Kishorganj'],
        'মানিকগঞ্জ' => ['Manikganj'],
        'ঢাকা' => ['Dhaka', 'Dacca'],
        'মুন্সিগঞ্জ' => ['Munshiganj'],
        'রাজবাড়ী' => ['Rajbari'],
        'মাদারীপুর' => ['Madaripur'],
        'গোপালগঞ্জ' => ['Gopalganj'],
        'ফরিদপুর' => ['Faridpur'],
        'পঞ্চগড়' => ['Panchagarh'],
        'দিনাজপুর' => ['Dinajpur'],
        'লালমনিরহাট' => ['Lalmonirhat'],
        'নীলফামারী' => ['Nilphamari'],
        'গাইবান্ধা' => ['Gaibandha'],
        'ঠাকুরগাঁও' => ['Thakurgaon'],
        'রংপুর' => ['Rangpur'],
        'কুড়িগ্রাম' => ['Kurigram'],
        'শেরপুর' => ['Sherpur'],
        'ময়মনসিংহ' => ['Mymensingh'],
        'জামালপুর' => ['Jamalpur'],
        'নেত্রকোণা' => ['Netrokona', 'Netrakona', 'নেত্রকোনা'],
    ],
];
