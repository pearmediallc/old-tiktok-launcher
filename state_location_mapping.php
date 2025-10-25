<?php
// State name to TikTok Location ID mapping for United States
// These are the TikTok Location IDs for US states

function getStateLocationMapping() {
    return [
        'alabama' => '4829764',
        'alaska' => '5879092',
        'arizona' => '5551752',
        'arkansas' => '4099753',
        'california' => '5332921',
        'colorado' => '5417618',
        'connecticut' => '4831725',
        'delaware' => '4142224',
        'florida' => '4155751',
        'georgia' => '4197000',
        'hawaii' => '5855797',
        'idaho' => '5596512',
        'illinois' => '4896861',
        'indiana' => '4921868',
        'iowa' => '4862182',
        'kansas' => '4273857',
        'kentucky' => '6254925',
        'louisiana' => '4331987',
        'maine' => '4971068',
        'maryland' => '4361885',
        'massachusetts' => '6254926',
        'michigan' => '5001836',
        'minnesota' => '5037779',
        'mississippi' => '4436296',
        'missouri' => '4398678',
        'montana' => '5667009',
        'nebraska' => '5073708',
        'nevada' => '5509151',
        'new hampshire' => '5090174',
        'new jersey' => '5101760',
        'new mexico' => '5481136',
        'new york' => '5128638',
        'north carolina' => '4482348',
        'north dakota' => '5690763',
        'ohio' => '4851445',
        'oklahoma' => '4544379',
        'oregon' => '5744337',
        'pennsylvania' => '6254927',
        'rhode island' => '5224323',
        'south carolina' => '4597040',
        'south dakota' => '5769223',
        'tennessee' => '4662168',
        'texas' => '4736286',
        'utah' => '5549030',
        'vermont' => '5242283',
        'virginia' => '6254928',
        'washington' => '5815135',
        'west virginia' => '4826850',
        'wisconsin' => '5279468',
        'wyoming' => '5843591',
        // Additional territories
        'district of columbia' => '4138106',
        'puerto rico' => '4566966',
        'virgin islands' => '4796775',
        'guam' => '4043988',
        'american samoa' => '5880801',
        'northern mariana islands' => '4041468'
    ];
}

function convertStateNamesToLocationIds($stateNames) {
    $mapping = getStateLocationMapping();
    $locationIds = [];
    $unmatchedStates = [];
    
    foreach ($stateNames as $stateName) {
        $normalizedName = strtolower(trim($stateName));
        
        if (isset($mapping[$normalizedName])) {
            $locationIds[] = $mapping[$normalizedName];
        } else {
            $unmatchedStates[] = $stateName;
        }
    }
    
    return [
        'location_ids' => $locationIds,
        'unmatched' => $unmatchedStates
    ];
}

function processLocationData($locationData) {
    // If all items are numeric, they're already location IDs
    $allNumeric = true;
    foreach ($locationData as $item) {
        if (!is_numeric($item)) {
            $allNumeric = false;
            break;
        }
    }
    
    if ($allNumeric) {
        return [
            'location_ids' => $locationData,
            'unmatched' => []
        ];
    }
    
    // Convert state names to location IDs
    return convertStateNamesToLocationIds($locationData);
}
?>