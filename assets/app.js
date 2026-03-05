// Global state
let state = {
    currentStep: 1,
    campaignId: null,
    adGroupId: null,
    ads: [],
    identities: [],
    mediaLibrary: [],
    selectedMedia: [],
    currentAdIndex: null,
    mediaSelectionMode: 'multiple' // Allow multiple selection
};

// Timezone Configuration
const TIMEZONE_CONFIG = {
    name: 'EST', // Options: 'EST', 'Colombia' - Change this to switch timezone
    displayName: 'Eastern Time (EST/EDT)',
    utcOffset: -5, // Base offset (EST = -5, Colombia = -5)
    autoDetectDST: true, // Automatically handle daylight saving for EST
    supportsDST: true // Whether this timezone supports daylight saving
};

// Colombia Timezone Functions
function getCurrentColombiaTime() {
    const now = new Date();
    // Get UTC time and convert to Colombia (UTC-5)
    const utcTime = now.getTime();
    const colombiaOffset = -5 * 60 * 60 * 1000; // UTC-5 in milliseconds
    const colombiaTime = new Date(utcTime + colombiaOffset);
    return colombiaTime;
}

function formatColombiaTime(date) {
    // Format date in Colombia timezone with seconds for API compatibility
    const utcDate = new Date(date.getTime());
    const year = utcDate.getUTCFullYear();
    const month = String(utcDate.getUTCMonth() + 1).padStart(2, '0');
    const day = String(utcDate.getUTCDate()).padStart(2, '0');
    const hours = String(utcDate.getUTCHours()).padStart(2, '0');
    const minutes = String(utcDate.getUTCMinutes()).padStart(2, '0');
    const seconds = String(utcDate.getUTCSeconds()).padStart(2, '0');
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

function formatColombiaTimeForInput(date) {
    const utcDate = new Date(date.getTime());
    const year = utcDate.getUTCFullYear();
    const month = String(utcDate.getUTCMonth() + 1).padStart(2, '0');
    const day = String(utcDate.getUTCDate()).padStart(2, '0');
    const hours = String(utcDate.getUTCHours()).padStart(2, '0');
    const minutes = String(utcDate.getUTCMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function convertLocalToColumbia(localDateTimeString) {
    if (!localDateTimeString) return null;

    // Parse local datetime as-is (user's browser timezone)
    const localDate = new Date(localDateTimeString);

    // Get the time in Colombia timezone (UTC-5)
    // Convert to UTC first, then apply Colombia offset
    const utcTime = localDate.getTime();
    const colombiaOffset = -5 * 60 * 60 * 1000; // UTC-5
    const colombiaTime = new Date(utcTime + colombiaOffset);

    return formatColombiaTime(colombiaTime);
}

function convertColumbiaToUTCForAPI(colombiaDateTimeString) {
    if (!colombiaDateTimeString) return null;
    
    // Parse Colombia time and convert to UTC for API
    const [datePart, timePart] = colombiaDateTimeString.split(' ');
    const [year, month, day] = datePart.split('-').map(Number);
    const timeComponents = timePart.split(':').map(Number);
    const hours = timeComponents[0];
    const minutes = timeComponents[1];
    const seconds = timeComponents[2] || 0; // Default to 0 if seconds not provided

    // Create date object treating input as Colombia time (UTC-5)
    // Colombia time + 5 hours = UTC
    const utcTime = new Date(Date.UTC(year, month - 1, day, hours + 5, minutes, seconds, 0));

    const result = utcTime.toISOString().replace('T', ' ').substring(0, 19);

    console.log('🇨🇴 → 🌐 Colombia to UTC conversion:', {
        colombia_input: colombiaDateTimeString,
        parsed: { year, month, day, hours, minutes, seconds },
        utc_hours: hours + 5,
        utc_output: result
    });

    return result;
}

// Initialize Colombia Time Selection UI
function initializeColombiaTimeSelection() {
    // Update current time display every minute
    updateCurrentTimeDisplay();
    setInterval(updateCurrentTimeDisplay, 60000);
    
    // Set up radio button event handlers
    const startNowRadio = document.getElementById('start-now');
    const startCustomRadio = document.getElementById('start-custom');
    const customTimeSection = document.getElementById('custom-time-section');
    const startDateInput = document.getElementById('start-date');
    
    if (startNowRadio) {
        startNowRadio.addEventListener('change', function() {
            if (this.checked) {
                customTimeSection.style.display = 'none';
                addLog('info', '🇨🇴 Selected: Start from now (Colombia Time)', {
                    option: 'start_from_now',
                    colombia_time: formatColombiaTime(getCurrentColombiaTime())
                });
            }
        });
    }
    
    if (startCustomRadio) {
        startCustomRadio.addEventListener('change', function() {
            if (this.checked) {
                customTimeSection.style.display = 'block';
                updateColombiaTimePreview();
                addLog('info', '🇨🇴 Selected: Choose specific time', {
                    option: 'custom_time'
                });
            }
        });
    }
    
    if (startDateInput) {
        startDateInput.addEventListener('change', updateColombiaTimePreview);
        startDateInput.addEventListener('input', updateColombiaTimePreview);
    }
}

// Update current time display
function updateCurrentTimeDisplay() {
    const display = document.getElementById('current-time-display');
    if (display) {
        const now = new Date();

        // Format user's local time
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const localTime = `${year}-${month}-${day} ${hours}:${minutes}`;

        // Get Colombia time
        const colombiaTime = getCurrentColombiaTime();
        const colombiaTimeFormatted = formatColombiaTime(colombiaTime);

        display.innerHTML = `
            <strong>Your Time:</strong> ${localTime}<br>
            <strong>Colombia Time:</strong> ${colombiaTimeFormatted} (UTC-5)
        `;
    }
}

// Update Colombia time preview for custom time selection
function updateColombiaTimePreview() {
    const preview = document.getElementById('colombia-time-preview');
    const startDateInput = document.getElementById('start-date');
    
    if (preview && startDateInput && startDateInput.value) {
        const localTime = startDateInput.value;
        const colombiaTime = convertLocalToColumbia(localTime);
        
        preview.innerHTML = `
            <strong>Your Input:</strong> ${localTime.replace('T', ' ')}<br>
            <strong>Colombia Time:</strong> ${colombiaTime} (UTC-5)
        `;
        
        addLog('info', '🇨🇴 Time conversion preview updated', {
            local_input: localTime,
            colombia_time: colombiaTime
        });
    }
}

// Get the final Colombia time for ad group creation
function getFinalColombiaTime() {
    const startNowRadio = document.getElementById('start-now');
    const startDateInput = document.getElementById('start-date');
    
    if (startNowRadio && startNowRadio.checked) {
        // Use current Colombia time
        const colombiaTime = formatColombiaTime(getCurrentColombiaTime());
        addLog('info', '🇨🇴 Using current Colombia time', {
            colombia_time: colombiaTime,
            source: 'current_time'
        });
        return colombiaTime;
    } else if (startDateInput && startDateInput.value) {
        // Convert user input to Colombia time
        const colombiaTime = convertLocalToColumbia(startDateInput.value);
        addLog('info', '🇨🇴 Using custom Colombia time', {
            local_input: startDateInput.value,
            colombia_time: colombiaTime,
            source: 'custom_time'
        });
        return colombiaTime;
    }
    
    return null;
}

// API Logger Functions
function addLog(type, message, details = null) {
    const logsContent = document.getElementById('logs-content');
    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;

    const now = new Date();
    const time = now.toTimeString().split(' ')[0];

    let typeLabel = '';
    if (type === 'request' || type === 'response' || type === 'error') {
        typeLabel = `<span class="log-type ${type}">${type.toUpperCase()}</span>`;
    }

    logEntry.innerHTML = `
        <span class="log-time">${time}</span>
        ${typeLabel}
        <span class="log-message">${message}</span>
    `;

    if (details) {
        const detailsDiv = document.createElement('div');
        detailsDiv.className = 'log-details';
        detailsDiv.innerHTML = `<pre>${JSON.stringify(details, null, 2)}</pre>`;
        logEntry.appendChild(detailsDiv);
    }

    logsContent.appendChild(logEntry);
    logsContent.scrollTop = logsContent.scrollHeight;
}

function clearLogs() {
    const logsContent = document.getElementById('logs-content');
    logsContent.innerHTML = `
        <div class="log-entry log-info">
            <span class="log-time">${new Date().toTimeString().split(' ')[0]}</span>
            <span class="log-message">Logs cleared - Ready for new requests</span>
        </div>
    `;
}

function toggleLogsPanel() {
    const logsPanel = document.getElementById('logs-panel');
    const toggleIcon = document.getElementById('logs-toggle-icon');
    const toggleBtn = document.querySelector('.btn-toggle-logs');

    logsPanel.classList.toggle('collapsed');

    if (logsPanel.classList.contains('collapsed')) {
        toggleIcon.textContent = '▲ Show Logs';
        toggleBtn.textContent = '▲';
    } else {
        toggleIcon.textContent = '▼ Hide Logs';
        toggleBtn.textContent = '▼';
    }
}

// Load Advertiser Info (timezone verification)
async function loadAdvertiserInfo() {
    try {
        const response = await apiRequest('get_advertiser_info', {}, 'GET');
        const statusElement = document.getElementById('timezone-status');

        if (response.success && response.data) {
            const data = response.data;
            const utcOffset = data.timezone_offset >= 0 ? `+${data.timezone_offset}` : data.timezone_offset;

            if (data.is_colombia) {
                statusElement.innerHTML = `<span style="color: #22c55e;">✓</span> Advertiser Timezone: <strong>${data.timezone}</strong> (UTC${utcOffset}) - Compatible with Eastern Time`;
                statusElement.style.color = '#22c55e';
                console.log('✅ Timezone is UTC-5. All times will work correctly for Eastern Time.');
            } else {
                statusElement.innerHTML = `<span style="color: #f59e0b;">⚠</span> Advertiser Timezone: <strong>${data.timezone}</strong> (UTC${utcOffset}) - <em>Not UTC-5</em>`;
                statusElement.style.color = '#f59e0b';
                console.warn('⚠️ Advertiser timezone is not UTC-5. Please change to America/New_York or America/Toronto for Eastern Time scheduling.');
            }

            console.log('Advertiser Info:', data);
        } else {
            statusElement.textContent = 'Unable to verify timezone';
            statusElement.style.color = '#ef4444';
        }
    } catch (error) {
        console.error('Failed to load advertiser info:', error);
        document.getElementById('timezone-status').textContent = 'Timezone verification failed';
        document.getElementById('timezone-status').style.color = '#ef4444';
    }
}

// Load TikTok timezones
async function loadTimezones() {
    try {
        const response = await apiRequest('get_timezones', {}, 'GET');
        const select = document.getElementById('timezone-select');

        if (response.success && response.data && response.data.timezones) {
            select.innerHTML = '<option value="">Select timezone...</option>';

            // Sort timezones by UTC offset for easier selection
            const timezones = response.data.timezones.sort((a, b) => a.utc_offset_hour - b.utc_offset_hour);

            timezones.forEach(tz => {
                const option = document.createElement('option');
                option.value = tz.timezone_id;
                option.textContent = `${tz.timezone_name} (UTC${tz.utc_offset_hour >= 0 ? '+' : ''}${tz.utc_offset_hour})`;
                option.setAttribute('data-offset', tz.utc_offset_hour);

                // Auto-select EST timezone (UTC-5)
                if (tz.utc_offset_hour === -5 && (tz.timezone_name.toLowerCase().includes('new_york') || tz.timezone_name.toLowerCase().includes('eastern'))) {
                    option.selected = true;
                    updateTimezoneInfo(tz);
                }

                select.appendChild(option);
            });

            // Add timezone change handler
            select.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    const tzData = {
                        timezone_id: selectedOption.value,
                        timezone_name: selectedOption.textContent,
                        utc_offset_hour: parseInt(selectedOption.getAttribute('data-offset'))
                    };
                    updateTimezoneInfo(tzData);
                }
            });
            
            console.log(`✅ Loaded ${timezones.length} timezones from TikTok API`);
        } else {
            select.innerHTML = '<option value="">Failed to load timezones</option>';
            console.error('Failed to load timezones:', response);
        }
    } catch (error) {
        console.error('Error loading timezones:', error);
        document.getElementById('timezone-select').innerHTML = '<option value="">Error loading timezones</option>';
    }
}

// Note: Timezone selection functions removed - using automatic EST conversion
// updateTimezoneInfo() and getSelectedTimezone() are no longer needed

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // APP SHELL MODE: Skip header/logout setup (shell handles it)
    // The manual form works within any container - no special handling needed
    const isShellMode = window.APP_SHELL_MODE === true;

    initializeDayparting();
    initializeLocationTargeting();
    loadIdentities();
    loadPixels();  // Load available pixels
    loadTimezones(); // Load TikTok timezones
    loadAdvertiserInfo(); // Load and verify advertiser timezone
    addFirstAd();
    initializeColombiaTimeSelection(); // Initialize Colombia time UI

    // Set default start date to tomorrow for ad group (will be overridden by Colombia time logic)
    const now = new Date();
    const easternTime = new Date(now.getTime() - (5 * 60 * 60 * 1000)); // UTC-5 for EST
    const tomorrow = new Date(easternTime);
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0); // Set to 9:00 AM Eastern time

    // Ad group start date - set default for custom time selection
    if (document.getElementById('start-date')) {
        document.getElementById('start-date').value = formatDateTimeLocal(tomorrow);
    }

    if (isShellMode) {
        console.log('Manual Campaign JS loaded in shell mode');
    }
});

// Format date for datetime-local input (Eastern Time EST/EDT)
function formatDateTimeLocal(date) {
    // Display the date as-is for Eastern Time input
    // User enters time as they want it in Eastern Time (EST/EDT)
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Check if a date is in Eastern Daylight Time (EDT)
function isEDT(date) {
    const year = date.getFullYear();
    
    // EDT starts second Sunday in March, 2:00 AM
    const startEDT = new Date(year, 2, 1); // March 1
    startEDT.setDate(14 - startEDT.getDay()); // Second Sunday
    if (startEDT.getDay() === 0) startEDT.setDate(startEDT.getDate() + 7); // If first is Sunday, go to second
    
    // EDT ends first Sunday in November, 2:00 AM
    const endEDT = new Date(year, 10, 1); // November 1
    endEDT.setDate(7 - endEDT.getDay()); // First Sunday
    if (endEDT.getDay() === 0) endEDT.setDate(endEDT.getDate() + 7); // If first is Sunday, go to next
    endEDT.setDate(endEDT.getDate() - 7); // Go back to first Sunday
    
    return date >= startEDT && date < endEDT;
}

// Convert EST/EDT to UTC for TikTok API (handles daylight saving automatically)
function convertESTToUTC(estDateTimeString) {
    if (!estDateTimeString) return null;
    
    // Parse the input as Eastern Time
    const [datePart, timePart] = estDateTimeString.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hours, minutes] = timePart.split(':').map(Number);
    
    // Create a date object to check if it's in DST
    const checkDate = new Date(year, month - 1, day);
    const isDST = isEDT(checkDate);
    
    // Determine offset: EST = UTC-5, EDT = UTC-4
    const utcOffset = isDST ? 4 : 5;
    const timezoneName = isDST ? 'EDT (UTC-4)' : 'EST (UTC-5)';
    
    // Format user input for display
    const userInputFormatted = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')} ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    
    // Add log showing user input and detected timezone
    addLog('info', `🇺🇸 User Input: ${userInputFormatted} (${timezoneName})`, {
        original_input: estDateTimeString,
        formatted_display: userInputFormatted,
        detected_timezone: timezoneName,
        is_daylight_saving: isDST,
        utc_offset_hours: utcOffset
    });
    
    // Create date object in Eastern timezone
    const easternTime = new Date();
    easternTime.setUTCFullYear(year);
    easternTime.setUTCMonth(month - 1); // Month is 0-indexed
    easternTime.setUTCDate(day);
    easternTime.setUTCHours(hours + utcOffset); // Add offset to convert to UTC
    easternTime.setUTCMinutes(minutes);
    easternTime.setUTCSeconds(0);
    easternTime.setUTCMilliseconds(0);
    
    const result = easternTime.toISOString().replace('T', ' ').substring(0, 19);
    
    // Add log showing the UTC conversion result
    addLog('info', `🌐 Converted to UTC: ${result} (for TikTok API)`, {
        eastern_time: userInputFormatted,
        timezone: timezoneName,
        utc_time: result,
        conversion_offset: `+${utcOffset} hours`
    });
    
    // Console logs for debugging
    console.log('🇺🇸 Converting Eastern Time to UTC:', estDateTimeString);
    console.log(`📍 Date ${year}-${month}-${day} is in ${timezoneName}`);
    console.log('✅ Eastern Time conversion complete:');
    console.log(`    📍 Eastern Time (${timezoneName}): ${userInputFormatted}`);
    console.log(`    🌐 UTC Time (for API): ${result}`);
    
    return result;
}

// Master timezone conversion function - uses configuration
function convertToUTC(dateTimeString, useTimezone = TIMEZONE_CONFIG.name) {
    if (!dateTimeString) return null;
    
    // Add log showing which timezone configuration is being used
    addLog('info', `⚙️ Timezone Configuration: ${TIMEZONE_CONFIG.displayName}`, {
        configured_timezone: useTimezone,
        display_name: TIMEZONE_CONFIG.displayName,
        base_utc_offset: TIMEZONE_CONFIG.utcOffset,
        auto_detect_dst: TIMEZONE_CONFIG.autoDetectDST,
        supports_dst: TIMEZONE_CONFIG.supportsDST
    });
    
    console.log(`🌍 Using timezone: ${useTimezone} (${TIMEZONE_CONFIG.displayName})`);
    
    switch (useTimezone) {
        case 'EST':
            return convertESTToUTC(dateTimeString);
        case 'Colombia':
            return convertColombiaToUTC(dateTimeString);
        default:
            console.warn(`⚠️ Unknown timezone: ${useTimezone}, falling back to EST`);
            addLog('warning', `⚠️ Unknown timezone '${useTimezone}', falling back to EST`);
            return convertESTToUTC(dateTimeString);
    }
}

// Convert Colombia Time to UTC for TikTok API
function convertColombiaToUTC(colombiaDateTimeString) {
    if (!colombiaDateTimeString) return null;

    // The datetime-local input gives us a local time string (what user sees as Colombia time)
    // We need to treat this as Colombia time and convert to UTC

    // Parse the input as if it's Colombia time
    const [datePart, timePart] = colombiaDateTimeString.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hours, minutes] = timePart.split(':').map(Number);

    // Create date object in Colombia timezone (UTC-5)
    const colombiaTime = new Date();
    colombiaTime.setUTCFullYear(year);
    colombiaTime.setUTCMonth(month - 1); // Month is 0-indexed
    colombiaTime.setUTCDate(day);
    colombiaTime.setUTCHours(hours + 5); // Add 5 hours to convert Colombia to UTC
    colombiaTime.setUTCMinutes(minutes);
    colombiaTime.setUTCSeconds(0);
    colombiaTime.setUTCMilliseconds(0);

    // Return in format expected by TikTok API
    return colombiaTime.toISOString().replace('T', ' ').substring(0, 19);
}

// Convert any timezone to UTC for TikTok API
function convertTimezoneToUTC(dateTimeString, timezone) {
    if (!dateTimeString || !timezone) return null;
    
    console.log('Converting timezone:', {
        input: dateTimeString,
        timezone: timezone.timezone_name,
        offset: timezone.utc_offset_hour
    });
    
    // The datetime-local input gives us a local time string
    // We need to treat this as the selected timezone and convert to UTC
    
    // Parse the input
    const [datePart, timePart] = dateTimeString.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hours, minutes] = timePart.split(':').map(Number);
    
    // Create UTC date object from timezone components
    // Subtract the timezone offset to convert to UTC
    const utcTime = new Date();
    utcTime.setUTCFullYear(year);
    utcTime.setUTCMonth(month - 1); // Month is 0-indexed
    utcTime.setUTCDate(day);
    utcTime.setUTCHours(hours - timezone.utc_offset_hour); // Subtract offset to get UTC
    utcTime.setUTCMinutes(minutes);
    utcTime.setUTCSeconds(0);
    utcTime.setUTCMilliseconds(0);
    
    const result = utcTime.toISOString().replace('T', ' ').substring(0, 19);
    console.log('Timezone conversion result:', result);
    
    // Return in format expected by TikTok API
    return result;
}

// Initialize dayparting grid
function initializeDayparting() {
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const tbody = document.getElementById('dayparting-body');

    days.forEach((day, dayIndex) => {
        const tr = document.createElement('tr');
        
        // Create the day cell with "Select All" checkbox
        const dayCell = document.createElement('td');
        dayCell.innerHTML = `
            <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
                <strong>${day}</strong>
                <label style="font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer;">
                    <input type="checkbox" class="day-select-all" data-day="${dayIndex}" 
                           onchange="toggleDayHours(${dayIndex})" style="transform: scale(0.8);">
                    <span>Select All</span>
                </label>
            </div>`;
        tr.appendChild(dayCell);

        for (let hour = 0; hour <= 24; hour++) {
            const td = document.createElement('td');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'hour-checkbox';
            checkbox.dataset.day = dayIndex;
            checkbox.dataset.hour = hour;
            checkbox.title = hour === 24 ? `${day} 24:00 (next day)` : `${day} ${hour}:00-${hour+1}:00`;
            checkbox.onchange = () => updateDaySelectAllState(dayIndex);
            // Don't check any by default
            checkbox.checked = false;
            td.appendChild(checkbox);
            tr.appendChild(td);
        }

        tbody.appendChild(tr);
    });
}

// Dayparting helper functions
function selectAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = true);
    // Update all day select-all checkboxes
    document.querySelectorAll('.day-select-all').forEach(cb => cb.checked = true);
}

function clearAllHours() {
    document.querySelectorAll('.hour-checkbox').forEach(cb => cb.checked = false);
    // Update all day select-all checkboxes
    document.querySelectorAll('.day-select-all').forEach(cb => cb.checked = false);
}

function selectBusinessHours() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        const day = parseInt(cb.dataset.day);
        // Monday-Friday (1-5), 8am-5pm (8-17)
        cb.checked = (day >= 1 && day <= 5 && hour >= 8 && hour < 17);
    });
    // Update day select-all states
    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
        updateDaySelectAllState(dayIndex);
    }
}

function selectPrimeTime() {
    clearAllHours();
    document.querySelectorAll('.hour-checkbox').forEach(cb => {
        const hour = parseInt(cb.dataset.hour);
        // All days, 6pm-10pm (18-22)
        cb.checked = (hour >= 18 && hour < 22);
    });
    // Update day select-all states
    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
        updateDaySelectAllState(dayIndex);
    }
}

// Toggle all hours for a specific day
function toggleDayHours(dayIndex) {
    const daySelectAll = document.querySelector(`.day-select-all[data-day="${dayIndex}"]`);
    const dayHours = document.querySelectorAll(`.hour-checkbox[data-day="${dayIndex}"]`);
    
    dayHours.forEach(cb => {
        cb.checked = daySelectAll.checked;
    });
}

// Update the state of a day's "Select All" checkbox based on individual hour selections
function updateDaySelectAllState(dayIndex) {
    const daySelectAll = document.querySelector(`.day-select-all[data-day="${dayIndex}"]`);
    const dayHours = document.querySelectorAll(`.hour-checkbox[data-day="${dayIndex}"]`);
    
    const checkedHours = Array.from(dayHours).filter(cb => cb.checked);
    
    if (checkedHours.length === 0) {
        daySelectAll.checked = false;
        daySelectAll.indeterminate = false;
    } else if (checkedHours.length === dayHours.length) {
        daySelectAll.checked = true;
        daySelectAll.indeterminate = false;
    } else {
        daySelectAll.checked = false;
        daySelectAll.indeterminate = true;
    }
}

// Toggle dayparting section
function toggleDayparting() {
    const enabled = document.getElementById('enable-dayparting').checked;
    document.getElementById('dayparting-section').style.display = enabled ? 'block' : 'none';
}

// Toggle CBO budget section
function toggleCBOBudget() {
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const campaignBudgetSection = document.getElementById('campaign-budget-section');
    const adGroupBudgetSection = document.getElementById('adgroup-budget-section');
    const cboBudgetNote = document.getElementById('cbo-budget-note');

    // Store CBO state for later use
    state.cboEnabled = cboEnabled;

    if (cboEnabled) {
        // CBO enabled: show campaign budget, hide ad group budget
        campaignBudgetSection.style.display = 'block';
        if (adGroupBudgetSection) {
            adGroupBudgetSection.style.display = 'none';
        }
        if (cboBudgetNote) {
            cboBudgetNote.style.display = 'block';
        }
    } else {
        // CBO disabled: hide campaign budget, show ad group budget
        campaignBudgetSection.style.display = 'none';
        if (adGroupBudgetSection) {
            adGroupBudgetSection.style.display = 'block';
        }
        if (cboBudgetNote) {
            cboBudgetNote.style.display = 'none';
        }
    }
}

// Get dayparting data
function getDaypartingData() {
    if (!document.getElementById('enable-dayparting').checked) {
        return {};
    }

    // TikTok format: 336 characters (7 days × 48 half-hour slots)
    // Each character represents a 30-minute slot
    // '1' = enabled, '0' = disabled
    // First char = Monday 00:00-00:30, Second = Monday 00:30-01:00, etc.
    let dayparting = '';

    // TikTok API expects: Monday to Sunday ordering
    // Our UI shows: Sunday to Saturday with hourly checkboxes
    // We need to reorder the days and duplicate each hour for two 30-min slots
    
    // Process in TikTok order: Monday (1), Tuesday (2), ..., Sunday (0)
    const tikTokDayOrder = [1, 2, 3, 4, 5, 6, 0]; // Mon, Tue, Wed, Thu, Fri, Sat, Sun
    
    for (let tikTokDay = 0; tikTokDay < 7; tikTokDay++) {
        const uiDay = tikTokDayOrder[tikTokDay];
        for (let hour = 0; hour <= 24; hour++) {
            // Convert Eastern Time (EST/EDT) to UTC for TikTok
            // Note: This is simplified - actual conversion should use convertToUTC()
            let utcHour = hour + 5; // EST is UTC-05:00, so add 5 to get UTC (simplified)
            let targetDay = uiDay;
            
            // Handle day overflow/underflow
            if (utcHour >= 24) {
                utcHour -= 24;
                targetDay = (uiDay + 1) % 7; // Next day
            } else if (utcHour < 0) {
                utcHour += 24;
                targetDay = (uiDay - 1 + 7) % 7; // Previous day
            }
            
            // Skip hour 24 for TikTok format (only 0-23)
            if (hour === 24) {
                continue;
            }
            
            const checkbox = document.querySelector(`.hour-checkbox[data-day="${uiDay}"][data-hour="${hour}"]`);
            const isChecked = checkbox && checkbox.checked;
            // Each hour creates two 30-minute slots
            dayparting += isChecked ? '11' : '00';
        }
    }

    // Must be exactly 336 characters (7 × 48)
    if (dayparting.length !== 336) {
        console.error('Dayparting string length is not 336:', dayparting.length);
        return {};
    }
    
    // Log for debugging
    console.log('Dayparting enabled, string length:', dayparting.length);
    console.log('First 48 chars (Monday):', dayparting.substring(0, 48));
    
    return {
        dayparting: dayparting
    };
}


// Step navigation
function nextStep() {
    if (state.currentStep < 4) {
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('active');
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('completed');
        document.getElementById(`step-${state.currentStep}`).classList.remove('active');

        state.currentStep++;

        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${state.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

function prevStep() {
    if (state.currentStep > 1) {
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('active');
        document.getElementById(`step-${state.currentStep}`).classList.remove('active');

        state.currentStep--;

        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.remove('completed');
        document.querySelector(`.step[data-step="${state.currentStep}"]`).classList.add('active');
        document.getElementById(`step-${state.currentStep}`).classList.add('active');

        window.scrollTo(0, 0);
    }
}

// Campaign creation
async function createCampaign() {
    const campaignName = document.getElementById('campaign-name').value.trim();

    // Get CBO settings
    const cboEnabled = document.getElementById('cbo-enabled').checked;
    const campaignBudget = cboEnabled ? (parseFloat(document.getElementById('campaign-budget').value) || 0) : null;

    // Validate required fields
    if (!campaignName) {
        showToast('Please enter campaign name', 'error');
        return;
    }

    if (cboEnabled && (!campaignBudget || campaignBudget < 20)) {
        showToast('Campaign budget must be at least $20 when CBO is enabled', 'error');
        return;
    }

    showLoading();

    try {
        const params = {
            campaign_name: campaignName,
            cbo_enabled: cboEnabled
        };

        // Set budget parameters based on CBO setting
        if (cboEnabled) {
            params.budget = campaignBudget;
            params.budget_mode = 'BUDGET_MODE_DAY';
            params.budget_optimize_on = true;
        } else {
            params.budget_mode = 'BUDGET_MODE_INFINITE';
            params.budget_optimize_on = false;
        }

        // Store budget mode for ad group to use
        state.campaignBudgetMode = 'BUDGET_MODE_DAY';

        const response = await apiRequest('create_campaign', params);

        if (response.success && response.data && response.data.campaign_id) {
            state.campaignId = response.data.campaign_id;
            // Display campaign ID on ad group step
            document.getElementById('display-campaign-id').textContent = state.campaignId;
            showToast('Campaign created successfully', 'success');
            nextStep();
        } else {
            showToast(response.message || 'Failed to create campaign', 'error');
        }
    } catch (error) {
        showToast('Error creating campaign: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Helper function to format date to TikTok format
function formatToTikTokDateTime(date) {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
    const seconds = '00';
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

// Ad Group creation
async function createAdGroup() {
    const adGroupName = document.getElementById('adgroup-name').value.trim();

    // Get pixel ID from either dropdown or manual input based on selection
    const pixelMethodRadio = document.querySelector('input[name="pixel-method"]:checked');
    const pixelMethod = pixelMethodRadio ? pixelMethodRadio.value : 'dropdown';
    const pixelId = pixelMethod === 'manual'
        ? document.getElementById('pixel-manual-input').value.trim()
        : document.getElementById('lead-gen-form-id').value.trim();

    // Check if CBO is enabled (budget at campaign level, not ad group)
    const cboEnabled = state.cboEnabled || document.getElementById('cbo-enabled')?.checked;

    const budgetMode = document.getElementById('budget-mode').value;
    const budget = cboEnabled ? null : parseFloat(document.getElementById('budget').value);
    const startDate = document.getElementById('start-date').value;
    const bidPrice = parseFloat(document.getElementById('bid-price').value);

    console.log('=== AD GROUP CREATION DEBUG ===');
    console.log('Pixel Method:', pixelMethod);
    console.log('Pixel ID:', pixelId);
    console.log('Pixel ID type:', typeof pixelId);
    console.log('Pixel ID length:', pixelId ? pixelId.length : 0);
    console.log('CBO Enabled:', cboEnabled);
    console.log('Budget:', budget);
    console.log('Dropdown value:', document.getElementById('lead-gen-form-id').value);
    console.log('Manual input value:', document.getElementById('pixel-manual-input').value);
    console.log('================================');

    // Validate age group selection
    const selectedAgeGroups = getSelectedAgeGroups();
    if (selectedAgeGroups.length === 0) {
        showToast('Please select at least one age group for targeting', 'error');
        return;
    }

    // Validate location targeting
    const selectedLocationIds = getSelectedLocationIds();
    console.log('Validation - selectedLocationIds:', selectedLocationIds);

    if (!selectedLocationIds || selectedLocationIds.length === 0) {
        showToast('Please select locations for targeting or upload a location file', 'error');
        return;
    }

    // Validation - budget is only required when CBO is disabled
    if (!adGroupName || !pixelId || !startDate) {
        showToast('Please fill in all required fields', 'error');
        console.error('Missing fields - Pixel ID:', pixelId);
        return;
    }

    // Validate budget only when CBO is disabled
    if (!cboEnabled && (!budget || budget < 20)) {
        showToast('Minimum budget is $20', 'error');
        return;
    }

    // Validate pixel_id is numeric
    if (!/^\d+$/.test(pixelId)) {
        showToast('Pixel ID must be numeric (e.g., 1234567890)', 'error');
        console.error('Invalid pixel ID format:', pixelId);
        return;
    }

    showLoading();

    try {
        // Get the Colombia time based on user selection
        const colombiaTime = getFinalColombiaTime();
        if (!colombiaTime) {
            showToast('Please select a start time for the ad group', 'error');
            hideLoading();
            return;
        }
        
        // Convert Colombia time to UTC for TikTok API
        const scheduleStartTime = convertColumbiaToUTCForAPI(colombiaTime);

        addLog('info', '🇨🇴 Colombia timezone conversion for API', {
            colombia_time: colombiaTime,
            utc_result: scheduleStartTime,
            timezone: 'Colombia (UTC-5)'
        });

        console.log('🇨🇴 Colombia timezone conversion:', {
            colombia_input: colombiaTime,
            utc_result: scheduleStartTime
        });

        // Based on TikTok screenshots: Complete ad group configuration
        const params = {
            // BASIC INFO
            campaign_id: state.campaignId,
            adgroup_name: adGroupName,

            // OPTIMIZATION (Lead Generation via Website Forms)
            promotion_type: 'LEAD_GENERATION',  // LEAD_GENERATION for lead gen campaigns
            promotion_target_type: 'EXTERNAL_WEBSITE',  // External website for lead generation
            pixel_id: pixelId,  // Pixel ID for tracking form conversions
            optimization_goal: 'CONVERT',  // CONVERT for lead forms
            optimization_event: 'FORM',  // FORM event for lead generation
            billing_event: 'OCPM',

            // ATTRIBUTION SETTINGS (Required for Lead Generation)
            click_attribution_window: 'SEVEN_DAYS',  // 7-day click attribution
            view_attribution_window: 'ONE_DAY',  // 1-day view attribution
            attribution_event_count: 'EVERY',  // Count every conversion

            // PLACEMENTS
            placement_type: 'PLACEMENT_TYPE_NORMAL',  // Select placement
            placements: ['PLACEMENT_TIKTOK'],  // TikTok only

            // DEMOGRAPHICS - TARGETING
            location_ids: selectedLocationIds,  // User-selected locations
            age_groups: selectedAgeGroups,  // User-selected age groups
            gender: 'GENDER_UNLIMITED',  // All genders

            // SCHEDULE
            schedule_type: 'SCHEDULE_FROM_NOW',  // Start from specified time, end 10 years later
            schedule_start_time: scheduleStartTime,  // UTC datetime string (will be converted to Unix timestamp by backend)

            // PACING
            pacing: 'PACING_MODE_SMOOTH',  // Standard delivery

            // DAYPARTING (optional)
            ...getDaypartingData()
        };

        // BUDGET - Only include at ad group level when CBO is disabled
        if (cboEnabled) {
            // When CBO is enabled, budget is at campaign level - use INFINITE mode for ad group
            params.budget_mode = 'BUDGET_MODE_INFINITE';
            console.log('CBO enabled - budget at campaign level, ad group budget mode: INFINITE');
        } else {
            // When CBO is disabled, budget is at ad group level
            params.budget_mode = budgetMode;
            params.budget = budget;
            console.log('CBO disabled - budget at ad group level:', budget);
        }

        // Set bidding type based on whether bid price is provided
        if (bidPrice && bidPrice > 0) {
            params.bid_type = 'BID_TYPE_CUSTOM';
            params.conversion_bid_price = bidPrice;  // Target CPA for conversions
        } else {
            params.bid_type = 'BID_TYPE_NO_BID';  // Let TikTok optimize automatically
        }

        console.log('Sending ad group params:', JSON.stringify(params, null, 2));

        const response = await apiRequest('create_adgroup', params);

        console.log('Ad group API response:', response);

        if (response.success && response.data && response.data.adgroup_id) {
            state.adGroupId = response.data.adgroup_id;
            showToast('Ad group created successfully', 'success');
            nextStep();
        } else {
            const errorMsg = response.message || 'Failed to create ad group';
            console.error('Ad group creation failed:', errorMsg);
            console.error('Full error response:', response);
            showToast(errorMsg, 'error');
        }
    } catch (error) {
        showToast('Error creating ad group: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Add first ad
function addFirstAd() {
    const adIndex = state.ads.length;
    addAdForm(adIndex);
    state.ads.push({ index: adIndex });
}

// Add ad form
function addAdForm(index, duplicateFrom = null) {
    const container = document.getElementById('ads-container');

    const adCard = document.createElement('div');
    adCard.className = 'ad-card';
    adCard.id = `ad-${index}`;

    const allCTAs = [
        'APPLY_NOW', 'BOOK_NOW', 'CALL_NOW', 'CHECK_AVAILABLILITY', 'CONTACT_US',
        'DOWNLOAD_NOW', 'EXPERIENCE_NOW', 'GET_QUOTE', 'GET_SHOWTIMES', 'GET_TICKETS_NOW',
        'INSTALL_NOW', 'INTERESTED', 'LEARN_MORE', 'LISTEN_NOW', 'ORDER_NOW',
        'PLAY_GAME', 'PREORDER_NOW', 'READ_MORE', 'SEND_MESSAGE', 'SHOP_NOW',
        'SIGN_UP', 'SUBSCRIBE', 'VIEW_NOW', 'VIEW_PROFILE', 'VISIT_STORE',
        'WATCH_LIVE', 'WATCH_NOW', 'JOIN_THIS_HASHTAG', 'SHOOT_WITH_THIS_EFFECT', 
        'VIEW_VIDEO_WITH_THIS_EFFECT'
    ];

    adCard.innerHTML = `
        <div class="ad-card-header">
            <h3>Ad #${index + 1}</h3>
            <div class="ad-card-actions">
                ${index > 0 ? '<button class="btn-icon" onclick="removeAd(' + index + ')" title="Delete">🗑️</button>' : ''}
            </div>
        </div>

        <div class="form-group">
            <label>Ad Name</label>
            <input type="text" id="ad-name-${index}" placeholder="Enter ad name" required>
        </div>

        <div class="form-group">
            <label>Creative Media (Video or Image)</label>
            <div class="creative-placeholder" onclick="openMediaModal(${index}, 'primary')">
                <span id="creative-placeholder-${index}">Click to select video or image</span>
            </div>
            <img id="creative-preview-${index}" class="creative-preview" style="display: none;">
            <input type="hidden" id="creative-id-${index}">
            <input type="hidden" id="creative-type-${index}">
        </div>

        <div class="form-group" id="cover-image-group-${index}" style="display: none;">
            <label>Cover Image (Required for Video Ads)</label>
            <div style="margin-bottom: 10px;">
                <button type="button" class="btn-secondary" onclick="useVideoThumbnail(${index})" 
                        id="use-thumbnail-btn-${index}" style="width: 100%;">
                    🎬 Use Video Thumbnail
                </button>
            </div>
            <div class="creative-placeholder" style="border-color: #667eea;">
                <span id="cover-placeholder-${index}">Click "Use Video Thumbnail" above</span>
            </div>
            <img id="cover-preview-${index}" class="creative-preview" style="display: none;">
            <input type="hidden" id="cover-image-id-${index}">
        </div>

        <div class="form-group">
            <label>Identity</label>
            <select id="identity-${index}" required>
                <option value="">Select identity...</option>
            </select>
        </div>

        <div class="form-group">
            <label>Ad Copy (Text)</label>
            <textarea id="ad-text-${index}" placeholder="Enter your ad copy" rows="3" required></textarea>
        </div>

        <div class="form-group">
            <label>Call to Action</label>
            <div style="margin-bottom: 15px;">
                <label style="display: inline-flex; align-items: center; margin-right: 20px;">
                    <input type="radio" name="cta-mode-${index}" value="static" checked onchange="toggleCTAMode(${index}, 'static')" style="margin-right: 8px;">
                    Static CTA
                </label>
                <label style="display: inline-flex; align-items: center;">
                    <input type="radio" name="cta-mode-${index}" value="dynamic" onchange="toggleCTAMode(${index}, 'dynamic')" style="margin-right: 8px;">
                    Dynamic CTA (Auto-Optimized)
                </label>
            </div>

            <div id="static-cta-section-${index}">
                <div class="cta-chips" id="cta-chips-${index}">
                    ${allCTAs.map(cta => `
                        <div class="cta-chip" data-cta="${cta}" onclick="selectCTA(${index}, '${cta}')">
                            ${cta.replace(/_/g, ' ')}
                        </div>
                    `).join('')}
                </div>
                <input type="hidden" id="cta-${index}" value="LEARN_MORE">
            </div>

            <div id="dynamic-cta-section-${index}" style="display: none;">
                <!-- Content Type Selection (Default) -->
                <div class="form-group">
                    <label>Content Type</label>
                    <select id="cta-content-type-${index}">
                        <option value="">Select content type...</option>
                        <option value="LANDING_PAGE">Landing Page</option>
                        <option value="APP_DOWNLOAD">App Download</option>
                        <option value="OTHER">Other</option>
                        <option value="MESSAGE">Message</option>
                        <option value="PHONE_CALL">Phone Call</option>
                    </select>
                </div>

                <div class="form-group" id="portfolio-option-section-${index}" style="display: none;">
                    <label style="font-weight: 600; margin-bottom: 10px; display: block;">Choose Portfolio Option:</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                        <button type="button" class="btn-secondary" onclick="loadExistingPortfolios(${index})">
                            📋 Use Existing Portfolio
                        </button>
                        <button type="button" class="btn-secondary" onclick="useFrequentlyUsedCTAs(${index})" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none;">
                            ⚡ Use Frequently Used CTAs
                        </button>
                        <button type="button" class="btn-secondary" onclick="loadDynamicCTAs(${index})">
                            ✨ Create New Portfolio
                        </button>
                    </div>
                </div>

                <div id="existing-portfolios-${index}" style="margin-top: 10px; display: none;">
                    <!-- Existing portfolios will be loaded here -->
                </div>

                <div id="dynamic-cta-list-${index}" style="margin-top: 10px;">
                    <!-- Dynamic CTAs will be loaded here -->
                </div>

                <!-- OR Divider -->
                <div style="text-align: center; margin: 20px 0; position: relative;">
                    <div style="border-top: 1px solid #e0e0e0; position: absolute; width: 100%; top: 50%;"></div>
                    <span style="background: white; padding: 0 15px; position: relative; color: #666; font-weight: 600;">OR</span>
                </div>

                <!-- Direct CTA Selection (Additional Option) -->
                <div id="cta-list-flow-${index}">
                    <div class="form-group">
                        <label style="font-weight: 600; margin-bottom: 15px; display: block; color: #f5576c;">Select CTAs (click multiple to add to portfolio)</label>
                        <div id="cta-selection-grid-${index}" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 15px;">
                            <!-- CTAs will be populated here -->
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 15px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; margin-bottom: 5px;">Selected CTAs: <span id="selected-cta-count-${index}">0</span></div>
                                <div id="selected-cta-list-${index}" style="font-size: 13px; color: #666;">
                                    No CTAs selected
                                </div>
                            </div>
                            <button type="button"
                                    id="create-portfolio-btn-${index}"
                                    onclick="createPortfolioFromSelectedCTAs(${index})"
                                    disabled
                                    style="padding: 12px 24px; background: linear-gradient(135deg, #28a745, #20c997); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; white-space: nowrap;">
                                ✓ Create Portfolio
                            </button>
                        </div>
                    </div>
                    <div id="cta-selection-result-${index}" style="margin-top: 15px;">
                        <!-- Result message will appear here -->
                    </div>
                </div>

                <input type="hidden" id="dynamic-cta-portfolio-${index}">
            </div>
        </div>

        <div class="form-group">
            <label>Destination URL (Optional - Only for tracking)</label>
            <input type="text" id="destination-url-${index}" placeholder="https://example.com/thank-you (optional for Lead Gen)">
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="auto-url-params-${index}" checked>
                Automatically add URL parameters
            </label>
        </div>
    `;

    container.appendChild(adCard);

    // Populate identities
    populateIdentitiesForAd(index);

    // Select LEARN_MORE as default CTA
    setTimeout(() => selectCTA(index, 'LEARN_MORE'), 100);

    // If duplicating, copy values
    if (duplicateFrom !== null) {
        setTimeout(() => {
            document.getElementById(`ad-name-${index}`).value =
                document.getElementById(`ad-name-${duplicateFrom}`).value + ' (Copy)';
            document.getElementById(`ad-text-${index}`).value =
                document.getElementById(`ad-text-${duplicateFrom}`).value;
            document.getElementById(`destination-url-${index}`).value =
                document.getElementById(`destination-url-${duplicateFrom}`).value;
            document.getElementById(`identity-${index}`).value =
                document.getElementById(`identity-${duplicateFrom}`).value;

            const creativeId = document.getElementById(`creative-id-${duplicateFrom}`).value;
            const creativeType = document.getElementById(`creative-type-${duplicateFrom}`).value;

            if (creativeId) {
                document.getElementById(`creative-id-${index}`).value = creativeId;
                document.getElementById(`creative-type-${index}`).value = creativeType;

                const preview = document.getElementById(`creative-preview-${duplicateFrom}`);
                if (preview && preview.style.display !== 'none') {
                    const newPreview = document.getElementById(`creative-preview-${index}`);
                    newPreview.src = preview.src;
                    newPreview.style.display = 'block';
                    document.getElementById(`creative-placeholder-${index}`).parentElement.style.display = 'none';
                }
            }
        }, 100);
    }
}

// Duplicate ad (single or multiple)
function duplicateAd(count = 1) {
    const lastAdIndex = state.ads.length - 1;

    for (let i = 0; i < count; i++) {
        const newIndex = state.ads.length;
        addAdForm(newIndex, lastAdIndex);
        state.ads.push({ index: newIndex });
    }

    if (count === 1) {
        showToast('Ad duplicated', 'success');
    } else {
        showToast(`${count} ads duplicated successfully`, 'success');
    }
}

// Duplicate multiple ads based on input
function duplicateAdBulk() {
    const countInput = document.getElementById('bulk-duplicate-count');
    const count = parseInt(countInput.value) || 1;

    if (count < 1) {
        showToast('Please enter a valid number (minimum 1)', 'error');
        return;
    }

    if (count > 50) {
        showToast('Maximum 50 ads can be duplicated at once', 'error');
        return;
    }

    if (state.ads.length === 0) {
        showToast('Please create at least one ad first', 'error');
        return;
    }

    duplicateAd(count);
}

// Remove ad
function removeAd(index) {
    if (confirm('Are you sure you want to delete this ad?')) {
        const adCard = document.getElementById(`ad-${index}`);
        adCard.remove();

        state.ads = state.ads.filter(ad => ad.index !== index);

        showToast('Ad removed', 'success');
    }
}

// Select CTA (single selection)
function selectCTA(adIndex, cta) {
    // Remove selected class from all CTAs for this ad
    const chips = document.querySelectorAll(`#cta-chips-${adIndex} .cta-chip`);
    chips.forEach(chip => chip.classList.remove('selected'));

    // Add selected class to clicked CTA
    const selectedChip = document.querySelector(`#cta-chips-${adIndex} .cta-chip[data-cta="${cta}"]`);
    if (selectedChip) {
        selectedChip.classList.add('selected');
    }

    // Update hidden input
    document.getElementById(`cta-${adIndex}`).value = cta;
}

// Toggle between static and dynamic CTA modes
function toggleCTAMode(adIndex, mode) {
    const staticSection = document.getElementById(`static-cta-section-${adIndex}`);
    const dynamicSection = document.getElementById(`dynamic-cta-section-${adIndex}`);

    if (mode === 'static') {
        staticSection.style.display = 'block';
        dynamicSection.style.display = 'none';
    } else {
        staticSection.style.display = 'none';
        dynamicSection.style.display = 'block';

        // Initialize content type dropdown handler
        const selectElement = document.getElementById(`cta-content-type-${adIndex}`);
        selectElement.onchange = function() {
            const portfolioOptionsDiv = document.getElementById(`portfolio-option-section-${adIndex}`);
            if (this.value) {
                portfolioOptionsDiv.style.display = 'block';
            } else {
                portfolioOptionsDiv.style.display = 'none';
            }
        };

        // Populate CTA grid for direct selection
        populateCTAGrid(adIndex);

        // Auto-load existing portfolios when switching to dynamic mode
        loadExistingPortfolios(adIndex);
    }
}

// Populate the CTA selection grid
function populateCTAGrid(adIndex) {
    const ctaGrid = document.getElementById(`cta-selection-grid-${adIndex}`);
    if (!ctaGrid) return;

    // Initialize selected CTAs storage
    if (!window.selectedCTAs) {
        window.selectedCTAs = {};
    }
    window.selectedCTAs[adIndex] = [];

    const ctas = Object.keys(CTA_ASSET_MAPPING);
    let gridHTML = '';
    ctas.forEach(ctaText => {
        gridHTML += `
            <button type="button"
                    id="cta-btn-${adIndex}-${ctaText.replace(/\s+/g, '_')}"
                    onclick="toggleCTASelection(${adIndex}, '${ctaText}', '${CTA_ASSET_MAPPING[ctaText]}')"
                    style="padding: 12px 15px; background: white; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s ease; text-align: left;">
                ${ctaText}
            </button>
        `;
    });

    ctaGrid.innerHTML = gridHTML;
}

// CTA to Asset ID mapping
const CTA_ASSET_MAPPING = {
    "Learn more": "1821962886917121",
    "Apply now": "1821962911664129",
    "Check if you qualify": "1821962913593345",
    "Check eligibility now": "1821962915044353",
    "Request a quote today": "1821962916651009",
    "Join today": "1821962918128641",
    "Read more": "1821962919685121",
    "View now": "1821962921241601",
    "Check it out": "1821962922560513",
    "Get it today": "1821962924158977",
    "Download now": "1821962925649921",
    "Download app now": "1821962927232001",
    "Install app": "1821962928747521",
    "Download today": "1821962930180097",
    "Try it now": "1821962931653633",
    "Install it now": "1821962933144577",
    "Download app": "1821962934635521",
    "Try it today": "1821962936175617",
    "Experience now": "1821962937691137",
    "Interested": "1821962939206657"
};

// Toggle CTA selection (add/remove from selection)
function toggleCTASelection(adIndex, ctaText, assetId) {
    if (!window.selectedCTAs) {
        window.selectedCTAs = {};
    }
    if (!window.selectedCTAs[adIndex]) {
        window.selectedCTAs[adIndex] = [];
    }

    const btnId = `cta-btn-${adIndex}-${ctaText.replace(/\s+/g, '_')}`;
    const btn = document.getElementById(btnId);

    // Check if already selected
    const existingIndex = window.selectedCTAs[adIndex].findIndex(item => item.text === ctaText);

    if (existingIndex >= 0) {
        // Deselect
        window.selectedCTAs[adIndex].splice(existingIndex, 1);
        btn.style.background = 'white';
        btn.style.borderColor = '#e0e0e0';
        btn.style.color = '#000';
    } else {
        // Select
        window.selectedCTAs[adIndex].push({
            text: ctaText,
            assetId: assetId
        });
        btn.style.background = 'linear-gradient(135deg, #f093fb, #f5576c)';
        btn.style.borderColor = '#f5576c';
        btn.style.color = 'white';
    }

    // Update selection display
    updateCTASelectionDisplay(adIndex);
}

// Update the selection count and list display
function updateCTASelectionDisplay(adIndex) {
    const selectedCTAs = window.selectedCTAs[adIndex] || [];
    const countSpan = document.getElementById(`selected-cta-count-${adIndex}`);
    const listDiv = document.getElementById(`selected-cta-list-${adIndex}`);
    const createBtn = document.getElementById(`create-portfolio-btn-${adIndex}`);

    countSpan.textContent = selectedCTAs.length;

    if (selectedCTAs.length === 0) {
        listDiv.textContent = 'No CTAs selected';
        listDiv.style.color = '#666';
        createBtn.disabled = true;
        createBtn.style.opacity = '0.5';
        createBtn.style.cursor = 'not-allowed';
    } else {
        listDiv.innerHTML = selectedCTAs.map(cta => `<span style="display: inline-block; padding: 4px 8px; background: #e8f5e9; border-radius: 4px; margin: 2px; font-size: 12px;">${cta.text}</span>`).join('');
        listDiv.style.color = '#28a745';
        createBtn.disabled = false;
        createBtn.style.opacity = '1';
        createBtn.style.cursor = 'pointer';
    }
}

// Create portfolio from selected CTAs
async function createPortfolioFromSelectedCTAs(adIndex) {
    const selectedCTAs = window.selectedCTAs[adIndex] || [];
    const resultDiv = document.getElementById(`cta-selection-result-${adIndex}`);

    if (selectedCTAs.length === 0) {
        showToast('Please select at least one CTA', 'error');
        return;
    }

    // Show loading
    const ctaList = selectedCTAs.map(c => c.text).join(', ');
    resultDiv.innerHTML = `
        <div style="padding: 15px; background: #e3f2fd; border: 1px solid #2196f3; border-radius: 8px;">
            <p style="margin: 0 0 10px 0; font-weight: 600; color: #1565c0;">🎯 Creating Portfolio with ${selectedCTAs.length} CTA(s)</p>
            <div style="background: white; padding: 10px; border-radius: 6px; font-size: 13px;">
                <div><strong>Selected CTAs:</strong> ${ctaList}</div>
                <div><strong>Count:</strong> ${selectedCTAs.length}</div>
            </div>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #1565c0;">⏳ Creating portfolio...</p>
        </div>
    `;

    try {
        const portfolioName = `CTA_Multi_${Date.now()}`;

        // Build portfolio_content array for TikTok API
        const portfolio_content = selectedCTAs.map(cta => ({
            asset_content: cta.text,
            asset_ids: [cta.assetId]
        }));

        // Create portfolio via API
        const response = await fetch('api.php?action=create_cta_portfolio', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN || ''
            },
            body: JSON.stringify({
                portfolio_name: portfolioName,
                portfolio_content: portfolio_content
            })
        });

        const data = await response.json();
        console.log('Create Portfolio Response:', data);

        if (data.success && data.portfolio_id) {
            // Store portfolio ID
            document.getElementById(`dynamic-cta-portfolio-${adIndex}`).value = data.portfolio_id;

            if (!window.adCTAPortfolios) {
                window.adCTAPortfolios = {};
            }
            window.adCTAPortfolios[adIndex] = data.portfolio_id;

            // Show success
            const ctaDetails = selectedCTAs.map(cta =>
                `<div style="padding: 6px; background: #e8f5e9; border-radius: 4px; margin: 4px 0;">• ${cta.text} (${cta.assetId})</div>`
            ).join('');

            resultDiv.innerHTML = `
                <div style="padding: 15px; background: #d4edda; border: 1px solid #28a745; border-radius: 8px;">
                    <p style="margin: 0 0 10px 0; font-weight: 600; color: #155724;">✅ Portfolio Created Successfully</p>
                    <div style="background: white; padding: 10px; border-radius: 6px; font-size: 13px;">
                        <div><strong>Portfolio Name:</strong> ${portfolioName}</div>
                        <div><strong>Portfolio ID:</strong> ${data.portfolio_id}</div>
                        <div><strong>Total CTAs:</strong> ${selectedCTAs.length}</div>
                        <div style="margin-top: 8px;"><strong>CTAs in Portfolio:</strong></div>
                        ${ctaDetails}
                        <div style="margin-top: 8px; color: #28a745; font-weight: 600;">✓ Stored in Database</div>
                    </div>
                    <p style="margin: 10px 0 0 0; font-size: 12px; color: #155724;">✓ This portfolio will be used when creating the ad</p>
                </div>
            `;

            showToast(`Portfolio created with ${selectedCTAs.length} CTA(s)`, 'success');
        } else {
            throw new Error(data.message || 'Failed to create portfolio');
        }

    } catch (error) {
        console.error('Error creating portfolio:', error);
        resultDiv.innerHTML = `
            <div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 8px;">
                <p style="margin: 0; color: #721c24;">❌ Failed to create portfolio: ${error.message}</p>
                <button onclick="createPortfolioFromSelectedCTAs(${adIndex})"
                        style="margin-top: 10px; padding: 8px 15px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Retry
                </button>
            </div>
        `;
        showToast('Failed to create portfolio', 'error');
    }
}

// Load dynamic CTAs from TikTok API
async function loadDynamicCTAs(adIndex) {
    const contentType = document.getElementById(`cta-content-type-${adIndex}`).value;
    const listContainer = document.getElementById(`dynamic-cta-list-${adIndex}`);
    const existingContainer = document.getElementById(`existing-portfolios-${adIndex}`);

    console.log('=== Load Dynamic CTAs Request ===');
    console.log('Ad Index:', adIndex);
    console.log('Content Type Selected:', contentType);

    // Hide existing portfolios section, show dynamic CTA creation section
    existingContainer.style.display = 'none';
    existingContainer.innerHTML = '';
    listContainer.style.display = 'block';

    // Validate content type is selected
    if (!contentType) {
        console.warn('Content type not selected - validation failed');
        listContainer.innerHTML = '<p style="color: #e74c3c;">Please select a content type first.</p>';
        showToast('Please select a content type', 'error');
        return;
    }

    // Show API request details in UI
    let loadingHtml = '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; margin-bottom: 10px;">';
    loadingHtml += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #856404;">🔄 API Request Details</p>';
    loadingHtml += '<div style="background: white; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto;">';
    loadingHtml += '<div><strong>TikTok Endpoint:</strong> /creative/cta/recommend/</div>';
    loadingHtml += '<div><strong>Method:</strong> GET</div>';
    loadingHtml += '<div><strong>Full URL:</strong> https://business-api.tiktok.com/open_api/v1.3/creative/cta/recommend/</div>';
    loadingHtml += `<div><strong>Parameters:</strong> advertiser_id, content_type=${contentType}</div>`;
    loadingHtml += '<div><strong>Headers:</strong> Access-Token</div>';
    loadingHtml += '</div>';
    loadingHtml += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #856404;">⏳ Fetching CTA recommendations...</p>';
    loadingHtml += '</div>';
    listContainer.innerHTML = loadingHtml;

    const requestUrl = `api.php?action=get_dynamic_ctas&content_type=${contentType}`;
    console.log('Request URL:', requestUrl);
    console.log('Sending GET request to TikTok API...');

    try {
        const response = await fetch(requestUrl);

        console.log('Response Status:', response.status);
        console.log('Response OK:', response.ok);
        console.log('Response Headers:', {
            'Content-Type': response.headers.get('content-type'),
            'Content-Length': response.headers.get('content-length')
        });

        const result = await response.json();

        console.log('=== API Response Received ===');
        console.log('Success:', result.success);
        console.log('Message:', result.message);
        console.log('Code:', result.code);
        console.log('Full Response Data:', JSON.stringify(result, null, 2));

        if (result.success && result.data && result.data.recommend_assets) {
            const assets = result.data.recommend_assets;
            console.log('Recommend Assets Count:', assets.length);
            console.log('Recommend Assets Details:', JSON.stringify(assets, null, 2));

            if (assets.length === 0) {
                console.warn('No dynamic CTAs returned for content type:', contentType);
                listContainer.innerHTML = '<p style="color: #666;">No dynamic CTAs available for this content type.</p>';
                return;
            }

            // Store the assets for portfolio creation
            window[`dynamicCTAAssets_${adIndex}`] = assets;
            console.log('Stored assets in window.dynamicCTAAssets_' + adIndex);

            // Display the dynamic CTAs with better formatting
            let html = '<div style="margin-bottom: 15px; padding: 15px; background: #f0f4ff; border-radius: 8px; border: 1px solid #667eea;">';
            html += '<p style="margin: 0 0 12px 0; font-weight: 600; color: #333;">Recommended Dynamic CTAs:</p>';
            html += '<div style="background: white; padding: 12px; border-radius: 6px; margin-bottom: 10px;">';

            assets.forEach((asset, idx) => {
                html += `<div style="padding: 8px 0; ${idx < assets.length - 1 ? 'border-bottom: 1px solid #e0e0e0;' : ''}">`;
                html += `<div style="font-weight: 600; color: #667eea; font-size: 15px; margin-bottom: 4px;">"${asset.asset_content}"</div>`;
                html += `<div style="font-size: 12px; color: #666;">${asset.asset_ids.length} variant${asset.asset_ids.length > 1 ? 's' : ''} • IDs: ${asset.asset_ids.join(', ')}</div>`;
                html += `</div>`;
            });

            html += '</div>';
            html += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;"><strong>Note:</strong> All CTAs above will be included in the portfolio and TikTok will auto-optimize which ones to show.</p>';
            html += '</div>';

            html += '<button type="button" class="btn-primary" onclick="createCTAPortfolio(' + adIndex + ')" style="width: 100%;">Create CTA Portfolio</button>';

            listContainer.innerHTML = html;

            console.log('UI updated with', assets.length, 'dynamic CTAs');
            showToast(`${assets.length} Dynamic CTAs loaded successfully`, 'success');
        } else {
            console.error('=== API Request Failed ===');
            console.error('Success:', result.success);
            console.error('Message:', result.message);
            console.error('Code:', result.code);
            console.error('Data:', result.data);

            listContainer.innerHTML = `<p style="color: #e74c3c;">Failed to load dynamic CTAs: ${result.message || 'Unknown error'}</p>`;
            showToast('Failed to load dynamic CTAs', 'error');
        }
    } catch (error) {
        console.error('=== Exception Caught ===');
        console.error('Error Type:', error.name);
        console.error('Error Message:', error.message);
        console.error('Error Stack:', error.stack);
        console.error('Full Error Object:', error);

        listContainer.innerHTML = '<p style="color: #e74c3c;">Error loading dynamic CTAs. Please try again.</p>';
        showToast('Error loading dynamic CTAs', 'error');
    }

    console.log('=== Load Dynamic CTAs Complete ===');
}

// Load existing CTA portfolios from TikTok account
async function loadExistingPortfolios(adIndex) {
    const existingContainer = document.getElementById(`existing-portfolios-${adIndex}`);
    const dynamicContainer = document.getElementById(`dynamic-cta-list-${adIndex}`);

    console.log('=== Load Existing Portfolios Request ===');
    console.log('Ad Index:', adIndex);

    // Hide dynamic CTA creation section, show existing portfolios section
    dynamicContainer.style.display = 'none';
    dynamicContainer.innerHTML = '';
    existingContainer.style.display = 'block';

    // Show API request details in UI
    let loadingHtml = '<div style="padding: 15px; background: #e8f5e9; border: 1px solid #4caf50; border-radius: 8px; margin-bottom: 10px;">';
    loadingHtml += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #2e7d32;">🔄 API Request Details</p>';
    loadingHtml += '<div style="background: white; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto;">';
    loadingHtml += '<div><strong>TikTok Endpoint:</strong> /creative/portfolio/list/</div>';
    loadingHtml += '<div><strong>Method:</strong> GET</div>';
    loadingHtml += '<div><strong>Full URL:</strong> https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/list/</div>';
    loadingHtml += '<div><strong>Parameters:</strong> advertiser_id, page=1, page_size=100</div>';
    loadingHtml += '<div><strong>Headers:</strong> Access-Token</div>';
    loadingHtml += '</div>';
    loadingHtml += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #2e7d32;">⏳ Fetching existing CTA portfolios...</p>';
    loadingHtml += '</div>';
    existingContainer.innerHTML = loadingHtml;

    const requestUrl = `api.php?action=get_cta_portfolios&page=1&page_size=100`;
    console.log('Request URL:', requestUrl);
    console.log('Sending GET request to TikTok API...');

    try {
        const response = await fetch(requestUrl);

        console.log('Response Status:', response.status);
        console.log('Response OK:', response.ok);

        const result = await response.json();

        console.log('=== API Response Received ===');
        console.log('Success:', result.success);
        console.log('Message:', result.message);
        console.log('Code:', result.code);
        console.log('Full Response Data:', JSON.stringify(result, null, 2));

        if (result.success && result.data && result.data.portfolios) {
            const portfolios = result.data.portfolios;
            console.log('CTA Portfolios Count:', portfolios.length);
            console.log('Portfolios Details:', JSON.stringify(portfolios, null, 2));

            if (portfolios.length === 0) {
                console.warn('No existing CTA portfolios found');
                let html = '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px;">';
                html += '<p style="margin: 0; color: #856404;">📋 No existing CTA portfolios found.</p>';
                html += '<p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">You can create a new portfolio using the "Create New Portfolio" button.</p>';
                html += '</div>';
                existingContainer.innerHTML = html;
                showToast('No existing portfolios found', 'info');
                return;
            }

            // Display the existing portfolios as a selection list
            let html = '<div style="margin-bottom: 15px; padding: 15px; background: #f0f4ff; border-radius: 8px; border: 2px solid #667eea;">';
            html += '<p style="margin: 0 0 12px 0; font-weight: 600; color: #333; font-size: 16px;">📋 Select an Existing CTA Portfolio</p>';
            html += `<p style="margin: 0 0 15px 0; font-size: 13px; color: #666;">Found ${portfolios.length} portfolio${portfolios.length > 1 ? 's' : ''}. Click to select one for your ad.</p>`;
            html += '<div style="background: white; padding: 12px; border-radius: 6px; max-height: 500px; overflow-y: auto;">';

            portfolios.forEach((portfolio, idx) => {
                const portfolioId = portfolio.creative_portfolio_id || portfolio.portfolio_id || 'N/A';
                const createdTime = portfolio.create_time ? new Date(portfolio.create_time * 1000).toLocaleDateString() : 'N/A';
                const portfolioName = portfolio.portfolio_name || `Portfolio ${portfolioId}`;
                const isFromTool = portfolio.created_by_tool || portfolio.from_database;
                const isFrequentlyUsed = portfolioName === 'Frequently Used CTAs';

                // Card styling based on portfolio type
                let cardIcon = '📋';
                let cardBadge = '';
                if (isFrequentlyUsed) {
                    cardIcon = '⚡';
                    cardBadge = '<span style="display: inline-block; padding: 2px 8px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 12px; font-size: 10px; font-weight: 600; margin-left: 8px;">FREQUENTLY USED</span>';
                } else if (isFromTool) {
                    cardIcon = '✨';
                    cardBadge = '<span style="display: inline-block; padding: 2px 8px; background: #4caf50; color: white; border-radius: 12px; font-size: 10px; font-weight: 600; margin-left: 8px;">CREATED BY TOOL</span>';
                }

                html += `<div class="portfolio-card" data-portfolio-id="${portfolioId}" style="padding: 15px; margin-bottom: 12px; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: white;"
                         onclick="selectExistingPortfolioWithDetails(${adIndex}, '${portfolioId}', this)"
                         onmouseover="this.style.borderColor='#667eea'; this.style.background='#f8f9ff'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.2)';"
                         onmouseout="this.style.borderColor='#e0e0e0'; this.style.background='white'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">`;

                html += `<div style="display: flex; align-items: center; margin-bottom: 8px;">`;
                html += `<div style="font-size: 24px; margin-right: 10px;">${cardIcon}</div>`;
                html += `<div style="flex: 1;">`;
                html += `<div style="font-weight: 600; color: #333; font-size: 15px;">${portfolioName}${cardBadge}</div>`;
                html += `<div style="font-size: 11px; color: #999; margin-top: 2px;">ID: ${portfolioId}</div>`;
                html += `</div>`;
                html += `</div>`;

                // Show portfolio content if available
                if (portfolio.portfolio_content && portfolio.portfolio_content.length > 0) {
                    html += `<div style="margin-top: 12px; padding: 10px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #667eea;">`;
                    html += `<div style="font-size: 11px; color: #666; font-weight: 600; margin-bottom: 6px;">📝 ${portfolio.portfolio_content.length} CTA${portfolio.portfolio_content.length > 1 ? 's' : ''} in this portfolio:</div>`;
                    html += `<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; margin-top: 6px;">`;
                    portfolio.portfolio_content.forEach(cta => {
                        if (cta.asset_content) {
                            const assetCount = cta.asset_ids ? cta.asset_ids.length : 0;
                            html += `<div style="padding: 6px 10px; background: white; border-radius: 4px; border: 1px solid #e0e0e0;">`;
                            html += `<div style="font-size: 12px; font-weight: 600; color: #667eea;">\"${cta.asset_content}\"</div>`;
                            html += `<div style="font-size: 10px; color: #999; margin-top: 2px;">${assetCount} asset${assetCount !== 1 ? 's' : ''}</div>`;
                            html += `</div>`;
                        }
                    });
                    html += `</div>`;
                    html += `</div>`;
                } else {
                    html += `<div style="margin-top: 8px; padding: 8px; background: #fff3cd; border-radius: 4px; border-left: 3px solid #ffc107;">`;
                    html += `<div style="font-size: 11px; color: #856404;">ℹ️ Click to load portfolio details</div>`;
                    html += `</div>`;
                }

                html += `</div>`;
            });

            html += '</div>';
            html += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;"><strong>Tip:</strong> Click on a portfolio to select it for your ad.</p>';
            html += '</div>';

            existingContainer.innerHTML = html;
            console.log('UI updated with', portfolios.length, 'existing portfolios');
            showToast(`${portfolios.length} portfolio${portfolios.length > 1 ? 's' : ''} loaded successfully`, 'success');

        } else {
            console.error('=== API Request Failed ===');
            console.error('Success:', result.success);
            console.error('Message:', result.message);
            console.error('Code:', result.code);
            console.error('Data:', result.data);

            let html = '<div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #721c24;">❌ Failed to Load Portfolios</p>';
            html += `<p style="margin: 0; font-size: 14px; color: #721c24;"><strong>Error:</strong> ${result.message || 'Unknown error'}</p>`;
            html += '<div style="background: white; padding: 10px; border-radius: 6px; margin-top: 10px;">';
            html += '<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 12px; color: #721c24;">Full API Response:</p>';
            html += '<pre style="margin: 0; font-family: monospace; font-size: 11px; color: #333; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto;">';
            html += JSON.stringify(result, null, 2);
            html += '</pre>';
            html += '</div>';
            html += '</div>';
            existingContainer.innerHTML = html;
            showToast('Failed to load existing portfolios', 'error');
        }
    } catch (error) {
        console.error('=== Exception Caught ===');
        console.error('Error Type:', error.name);
        console.error('Error Message:', error.message);
        console.error('Error Stack:', error.stack);

        existingContainer.innerHTML = '<p style="color: #e74c3c;">Error loading existing portfolios. Please try again.</p>';
        showToast('Error loading existing portfolios', 'error');
    }

    console.log('=== Load Existing Portfolios Complete ===');
}

// Use Frequently Used CTAs - Get or create portfolio with predefined CTAs
async function useFrequentlyUsedCTAs(adIndex) {
    const existingContainer = document.getElementById(`existing-portfolios-${adIndex}`);
    const dynamicContainer = document.getElementById(`dynamic-cta-list-${adIndex}`);

    console.log('=== Use Frequently Used CTAs ===');
    console.log('Ad Index:', adIndex);

    // Hide other sections
    existingContainer.style.display = 'none';
    existingContainer.innerHTML = '';
    dynamicContainer.style.display = 'block';

    // Show loading message
    let loadingHtml = '<div style="padding: 15px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border: 2px solid #4caf50; border-radius: 8px; margin-bottom: 10px;">';
    loadingHtml += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #2e7d32;">⚡ Frequently Used CTAs</p>';
    loadingHtml += '<div style="background: white; padding: 12px; border-radius: 6px; font-size: 13px;">';
    loadingHtml += '<p style="margin: 0 0 8px 0; color: #2e7d32;"><strong>Checking for existing portfolio...</strong></p>';
    loadingHtml += '<p style="margin: 0; font-size: 12px; color: #666;">This will use or create a portfolio with these CTAs:</p>';
    loadingHtml += '<ul style="margin: 8px 0 0 20px; font-size: 12px; color: #555;">';
    loadingHtml += '<li>Learn more</li>';
    loadingHtml += '<li>Check it out</li>';
    loadingHtml += '<li>View now</li>';
    loadingHtml += '<li>Read more</li>';
    loadingHtml += '<li>Apply now</li>';
    loadingHtml += '</ul>';
    loadingHtml += '</div>';
    loadingHtml += '</div>';
    dynamicContainer.innerHTML = loadingHtml;

    const requestUrl = `api.php?action=get_or_create_frequently_used_cta_portfolio`;
    console.log('Request URL:', requestUrl);

    try {
        const response = await fetch(requestUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        console.log('Response Status:', response.status);
        const result = await response.json();

        console.log('=== API Response ===');
        console.log('Success:', result.success);
        console.log('Message:', result.message);
        console.log('Portfolio ID:', result.data?.portfolio_id);
        console.log('Created New:', result.data?.created_new);
        console.log('Full Response:', JSON.stringify(result, null, 2));

        if (result.success && result.data?.portfolio_id) {
            const portfolioId = result.data.portfolio_id;
            const isNew = result.data.created_new;

            // Store the portfolio ID
            document.getElementById(`dynamic-cta-portfolio-${adIndex}`).value = portfolioId;

            // Show success message with portfolio details
            let html = '<div style="padding: 15px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border: 2px solid #4caf50; border-radius: 8px;">';
            html += '<p style="margin: 0 0 12px 0; font-weight: 700; color: #2e7d32; font-size: 16px;">✅ ' + (isNew ? 'Portfolio Created Successfully!' : 'Using Existing Portfolio') + '</p>';

            html += '<div style="background: white; padding: 15px; border-radius: 6px; margin-bottom: 12px;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #333;"><strong>Portfolio ID:</strong> ' + portfolioId + '</p>';
            html += '<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 13px; color: #555;">Frequently Used CTAs:</p>';
            html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px;">';

            const ctas = [
                { text: 'Learn more', ids: '201781, 201535' },
                { text: 'Check it out', ids: '202156, 202150' },
                { text: 'View now', ids: '202001, 201529' },
                { text: 'Read more', ids: '201829, 201621' },
                { text: 'Apply now', ids: '201963, 201489' }
            ];

            ctas.forEach(cta => {
                html += `<div style="padding: 10px; background: #f8f9ff; border: 1px solid #667eea; border-radius: 6px;">`;
                html += `<div style="font-weight: 600; color: #333; font-size: 13px; margin-bottom: 4px;">"${cta.text}"</div>`;
                html += `<div style="font-size: 11px; color: #666;">IDs: ${cta.ids}</div>`;
                html += `</div>`;
            });

            html += '</div>';
            html += '</div>';

            if (isNew) {
                html += '<div style="background: #fff3cd; padding: 12px; border-radius: 6px; border: 1px solid #ffc107;">';
                html += '<p style="margin: 0; font-size: 13px; color: #856404;"><strong>✅ Portfolio Created!</strong></p>';
                html += '<p style="margin: 8px 0 0 0; font-size: 12px; color: #856404;">This portfolio is now saved as <strong>"Frequently Used CTAs"</strong> and will appear in the <strong>"📋 Use Existing Portfolio"</strong> list for all future campaigns on this advertiser account.</p>';
                html += '</div>';
            } else {
                html += '<div style="background: #d1ecf1; padding: 12px; border-radius: 6px; border: 1px solid #bee5eb;">';
                html += '<p style="margin: 0; font-size: 13px; color: #0c5460;"><strong>♻️ Portfolio Already Exists!</strong></p>';
                html += '<p style="margin: 8px 0 0 0; font-size: 12px; color: #0c5460;">This portfolio already exists and can be found in <strong>"📋 Use Existing Portfolio"</strong> as <strong>"Frequently Used CTAs"</strong>. No need to create it again!</p>';
                html += '</div>';
            }

            html += '</div>';

            dynamicContainer.innerHTML = html;
            showToast(isNew ? 'Frequently used CTA portfolio created!' : 'Using existing frequently used CTA portfolio', 'success');
        } else {
            console.error('Failed to get/create portfolio');
            console.error('Message:', result.message);
            console.error('Response:', result);

            let html = '<div style="padding: 15px; background: #f8d7da; border: 2px solid #f5c6cb; border-radius: 8px;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #721c24;">❌ Failed to Create Portfolio</p>';
            html += `<p style="margin: 0; font-size: 14px; color: #721c24;"><strong>Error:</strong> ${result.message || 'Unknown error'}</p>`;
            html += '<div style="background: white; padding: 10px; border-radius: 6px; margin-top: 10px;">';
            html += '<pre style="margin: 0; font-family: monospace; font-size: 11px; max-height: 200px; overflow-y: auto;">';
            html += JSON.stringify(result, null, 2);
            html += '</pre>';
            html += '</div>';
            html += '</div>';
            dynamicContainer.innerHTML = html;
            showToast('Failed to create frequently used CTA portfolio', 'error');
        }
    } catch (error) {
        console.error('=== Exception Caught ===');
        console.error('Error:', error);

        dynamicContainer.innerHTML = '<p style="color: #e74c3c;">Error loading frequently used CTAs. Please try again.</p>';
        showToast('Error loading frequently used CTAs', 'error');
    }

    console.log('=== Use Frequently Used CTAs Complete ===');
}

// Select an existing portfolio with details - enhanced version
async function selectExistingPortfolioWithDetails(adIndex, portfolioId, element) {
    console.log('=== Select Existing Portfolio With Details ===');
    console.log('Ad Index:', adIndex);
    console.log('Portfolio ID:', portfolioId);

    // Store the selected portfolio ID
    document.getElementById(`dynamic-cta-portfolio-${adIndex}`).value = portfolioId;

    // Visual feedback - highlight selected portfolio
    const container = element.parentElement;
    Array.from(container.children).forEach(child => {
        child.style.borderColor = '#e0e0e0';
        child.style.background = 'white';
        child.style.borderWidth = '2px';
    });

    element.style.borderColor = '#4caf50';
    element.style.background = '#f1f8f4';
    element.style.borderWidth = '3px';

    // Fetch portfolio details to show what CTAs will be used
    try {
        const response = await fetch(`api.php?action=get_portfolio_details&portfolio_id=${portfolioId}`);
        const result = await response.json();

        console.log('Portfolio Details:', result);

        // Show success message with portfolio details
        const existingContainer = document.getElementById(`existing-portfolios-${adIndex}`);
        let successMsg = document.getElementById(`portfolio-success-${adIndex}`);

        if (!successMsg) {
            successMsg = document.createElement('div');
            successMsg.id = `portfolio-success-${adIndex}`;
            existingContainer.appendChild(successMsg);
        }

        let html = '<div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #d4edda, #c3e6cb); border: 2px solid #28a745; border-radius: 8px; box-shadow: 0 2px 8px rgba(40, 167, 69, 0.2);">';
        html += '<div style="display: flex; align-items: center; margin-bottom: 12px;">';
        html += '<div style="font-size: 24px; margin-right: 10px;">✅</div>';
        html += '<div><div style="font-weight: 600; color: #155724; font-size: 16px;">Portfolio Selected Successfully!</div>';
        html += `<div style="font-size: 12px; color: #155724; margin-top: 2px;">Portfolio ID: ${portfolioId}</div></div>`;
        html += '</div>';

        if (result.success && result.data && result.data.portfolio_content) {
            const content = result.data.portfolio_content;
            const portfolioName = result.data.portfolio_name || 'Selected Portfolio';

            html += '<div style="background: white; padding: 12px; border-radius: 6px; margin-top: 10px;">';
            html += `<div style="font-size: 13px; font-weight: 600; color: #333; margin-bottom: 8px;">📝 ${portfolioName}</div>`;
            html += `<div style="font-size: 12px; color: #666; margin-bottom: 10px;">This ad will use the following ${content.length} CTA${content.length > 1 ? 's' : ''}:</div>`;

            html += '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">';
            content.forEach((cta, idx) => {
                if (cta.asset_content) {
                    const assetCount = cta.asset_ids ? cta.asset_ids.length : 0;
                    html += '<div style="padding: 10px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #667eea;">';
                    html += `<div style="font-size: 13px; font-weight: 600; color: #667eea; margin-bottom: 4px;">${idx + 1}. "${cta.asset_content}"</div>`;
                    html += `<div style="font-size: 11px; color: #999;">Asset IDs: ${assetCount}</div>`;
                    if (cta.asset_ids && cta.asset_ids.length > 0) {
                        html += `<div style="font-size: 10px; color: #999; margin-top: 2px;">${cta.asset_ids.slice(0, 2).join(', ')}${cta.asset_ids.length > 2 ? '...' : ''}</div>`;
                    }
                    html += '</div>';
                }
            });
            html += '</div>';
            html += '</div>';
        } else {
            html += '<div style="background: white; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 12px; color: #666;">';
            html += `✓ Portfolio ${portfolioId} has been selected and will be used for your ad.`;
            html += '</div>';
        }

        html += '</div>';
        successMsg.innerHTML = html;

        console.log('Portfolio selected and details loaded');
        showToast('Portfolio selected successfully', 'success');
    } catch (error) {
        console.error('Error fetching portfolio details:', error);

        // Still show success message even if details fetch fails
        const existingContainer = document.getElementById(`existing-portfolios-${adIndex}`);
        let successMsg = document.getElementById(`portfolio-success-${adIndex}`);

        if (!successMsg) {
            successMsg = document.createElement('div');
            successMsg.id = `portfolio-success-${adIndex}`;
            existingContainer.appendChild(successMsg);
        }

        successMsg.innerHTML = `<div style="margin-top: 15px; padding: 12px; background: #d4edda; border: 2px solid #28a745; border-radius: 6px;"><strong style="color: #155724;">✓ Selected Portfolio ID:</strong> <span style="color: #155724;">${portfolioId}</span></div>`;
        showToast('Portfolio selected successfully', 'success');
    }
}

// Legacy function for backward compatibility
function selectExistingPortfolio(adIndex, portfolioId, element) {
    selectExistingPortfolioWithDetails(adIndex, portfolioId, element);
}

// Create CTA portfolio from dynamic CTAs
async function createCTAPortfolio(adIndex) {
    console.log('=== Create CTA Portfolio Request ===');
    console.log('Ad Index:', adIndex);

    const assets = window[`dynamicCTAAssets_${adIndex}`];

    console.log('Loaded Assets from window:', assets);
    console.log('Assets Count:', assets ? assets.length : 0);

    if (!assets || assets.length === 0) {
        console.warn('No dynamic CTAs available - cannot create portfolio');
        showToast('No dynamic CTAs available. Please load CTAs first.', 'error');
        return;
    }

    const listContainer = document.getElementById(`dynamic-cta-list-${adIndex}`);

    // Build portfolio_content array as per TikTok API spec
    // Ensure asset_ids are strings as per TikTok API requirement
    const portfolioContent = assets.map(asset => ({
        asset_content: asset.asset_content,
        asset_ids: asset.asset_ids.map(id => String(id))
    }));

    console.log('Portfolio Content Structure:', JSON.stringify(portfolioContent, null, 2));
    console.log('Number of CTAs in Portfolio:', portfolioContent.length);

    // Validate portfolio content
    console.log('Validating portfolio content before sending...');
    portfolioContent.forEach((item, idx) => {
        console.log(`  CTA ${idx + 1}:`, {
            asset_content: item.asset_content,
            asset_ids_count: item.asset_ids.length,
            asset_ids: item.asset_ids,
            asset_ids_types: item.asset_ids.map(id => typeof id)
        });
    });

    const requestBody = {
        portfolio_content: portfolioContent
    };

    // Show API request details in UI
    let loadingHtml = '<div style="padding: 15px; background: #d1ecf1; border: 1px solid #17a2b8; border-radius: 8px; margin-bottom: 10px;">';
    loadingHtml += '<p style="margin: 0 0 10px 0; font-weight: 600; color: #0c5460;">📤 Creating CTA Portfolio - API Request</p>';
    loadingHtml += '<div style="background: white; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto;">';
    loadingHtml += '<div><strong>TikTok Endpoint:</strong> /creative/portfolio/create/</div>';
    loadingHtml += '<div><strong>Method:</strong> POST</div>';
    loadingHtml += '<div><strong>Full URL:</strong> https://business-api.tiktok.com/open_api/v1.3/creative/portfolio/create/</div>';
    loadingHtml += '<div><strong>Headers:</strong> Access-Token, Content-Type: application/json</div>';
    loadingHtml += '<div style="margin-top: 8px;"><strong>Request Body:</strong></div>';
    loadingHtml += '<div style="background: #f8f9fa; padding: 8px; border-radius: 4px; margin-top: 4px; max-height: 200px; overflow-y: auto;">';
    loadingHtml += '<pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">' + JSON.stringify({
        advertiser_id: '{current_advertiser}',
        creative_portfolio_type: 'CTA',
        portfolio_content: portfolioContent
    }, null, 2) + '</pre>';
    loadingHtml += '</div>';
    loadingHtml += '</div>';
    loadingHtml += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #0c5460;">⏳ Sending request to TikTok API...</p>';
    loadingHtml += '</div>';
    listContainer.innerHTML = loadingHtml;

    console.log('Request Body:', JSON.stringify(requestBody, null, 2));
    console.log('Sending POST request to create portfolio...');

    try {
        const response = await fetch('api.php?action=create_cta_portfolio', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN || ''
            },
            body: JSON.stringify(requestBody)
        });

        console.log('Response Status:', response.status);
        console.log('Response OK:', response.ok);
        console.log('Response Headers:', {
            'Content-Type': response.headers.get('content-type'),
            'Content-Length': response.headers.get('content-length')
        });

        const result = await response.json();

        console.log('=== API Response Received ===');
        console.log('Success:', result.success);
        console.log('Message:', result.message);
        console.log('Code:', result.code);
        console.log('Full Response Data:', JSON.stringify(result, null, 2));

        // TikTok API returns creative_portfolio_id, backend normalizes to portfolio_id
        const portfolioId = result.data?.portfolio_id || result.data?.creative_portfolio_id;

        if (result.success && result.data && portfolioId) {
            console.log('Portfolio Created Successfully!');
            console.log('Portfolio ID:', portfolioId);
            console.log('Raw data received:', result.data);

            // Store the portfolio ID
            document.getElementById(`dynamic-cta-portfolio-${adIndex}`).value = portfolioId;
            console.log('Stored portfolio ID in hidden input field');

            // Update the UI to show success with API response
            let html = '<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; color: #155724;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: 600;">✅ CTA Portfolio Created Successfully</p>';
            html += `<p style="margin: 0; font-size: 14px;"><strong>Portfolio ID:</strong> ${portfolioId}</p>`;
            html += '<p style="margin: 10px 0; font-size: 12px;">This portfolio will be used when creating your ad.</p>';
            html += '<div style="background: white; padding: 10px; border-radius: 6px; margin-top: 10px;">';
            html += '<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 12px; color: #155724;">API Response:</p>';
            html += '<pre style="margin: 0; font-family: monospace; font-size: 11px; color: #333; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto;">';
            html += JSON.stringify(result, null, 2);
            html += '</pre>';
            html += '</div>';
            html += '</div>';

            listContainer.innerHTML = html;

            console.log('UI updated with success message');
            showToast('CTA Portfolio created successfully', 'success');
        } else {
            console.error('=== Portfolio Creation Failed ===');
            console.error('Success:', result.success);
            console.error('Message:', result.message);
            console.error('Code:', result.code);
            console.error('Data:', result.data);
            console.error('Raw Response:', result.raw_response);

            // Show detailed error message with full API response
            let errorMsg = result.message || 'Unknown error';
            if (result.raw_response) {
                console.error('TikTok API Full Response:', JSON.stringify(result.raw_response, null, 2));
            }

            let html = '<div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; color: #721c24;">';
            html += '<p style="margin: 0 0 10px 0; font-weight: 600;">❌ Portfolio Creation Failed</p>';
            html += `<p style="margin: 0; font-size: 14px;"><strong>Error:</strong> ${errorMsg}</p>`;
            html += `<p style="margin: 5px 0 0 0; font-size: 13px;"><strong>Code:</strong> ${result.code || 'N/A'}</p>`;
            html += '<div style="background: white; padding: 10px; border-radius: 6px; margin-top: 10px;">';
            html += '<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 12px; color: #721c24;">Full API Response:</p>';
            html += '<pre style="margin: 0; font-family: monospace; font-size: 11px; color: #333; white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto;">';
            html += JSON.stringify(result, null, 2);
            html += '</pre>';
            html += '</div>';
            html += '</div>';

            listContainer.innerHTML = html;
            showToast('Failed to create CTA portfolio: ' + errorMsg, 'error');
        }
    } catch (error) {
        console.error('=== Exception Caught ===');
        console.error('Error Type:', error.name);
        console.error('Error Message:', error.message);
        console.error('Error Stack:', error.stack);
        console.error('Full Error Object:', error);

        listContainer.innerHTML = '<p style="color: #e74c3c;">Error creating portfolio. Please try again.</p>';
        showToast('Error creating CTA portfolio', 'error');
    }

    console.log('=== Create CTA Portfolio Complete ===');
}

// Media modal
function openMediaModal(adIndex, selectionType = 'primary') {
    state.currentAdIndex = adIndex;
    state.currentSelectionType = selectionType;
    state.selectedMedia = []; // Reset selection

    const modal = document.getElementById('media-modal');
    modal.classList.add('show');

    // Update modal title and tabs based on selection type
    const modalTitle = modal.querySelector('.modal-header h3');
    const modalTabs = modal.querySelector('.modal-tabs');
    
    if (selectionType === 'cover') {
        modalTitle.innerHTML = 'Select Cover Image <span style="font-size: 14px; color: #667eea; margin-left: 10px;">(Images only)</span>';
        
        // Update tabs for image-only selection
        modalTabs.innerHTML = `
            <button class="tab-btn active" onclick="switchMediaTab('library', event)">Image Library</button>
            <button class="tab-btn" onclick="switchMediaTab('upload', event)">Upload Image</button>
            <button class="btn-secondary btn-sm" onclick="loadImageLibrary()" style="margin-left: auto;">🔄 Refresh</button>
            <button class="btn-secondary btn-sm" onclick="syncImagesFromTikTok()">📥 Sync from TikTok</button>
        `;
        
        loadImageLibrary(); // Load only images for cover selection
    } else {
        modalTitle.innerHTML = 'Select Media <span id="selection-counter" style="font-size: 14px; color: #667eea; margin-left: 10px; display: none;"></span>';
        
        // Restore default tabs
        modalTabs.innerHTML = `
            <button class="tab-btn active" onclick="switchMediaTab('library', event)">Library</button>
            <button class="tab-btn" onclick="switchMediaTab('upload', event)">Upload New</button>
            <button class="btn-secondary btn-sm" onclick="refreshMediaLibrary()" style="margin-left: auto;">🔄 Refresh</button>
            <button class="btn-secondary btn-sm" onclick="syncTikTokLibrary()">📥 Sync from TikTok</button>
        `;
        
        loadMediaLibrary(); // Load all media
    }
    
    updateSelectionCounter();
}

function closeMediaModal() {
    const modal = document.getElementById('media-modal');
    modal.classList.remove('show');
    state.currentAdIndex = null;
    state.selectedMedia = [];
}

function switchMediaTab(tab, evt) {
    // Get event from parameter
    const clickEvent = evt;
    
    // Remove active class from all tabs and buttons
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.media-tab').forEach(tabContent => tabContent.classList.remove('active'));

    // Add active class to clicked button (if event exists) and corresponding tab
    if (clickEvent && clickEvent.target) {
        clickEvent.target.classList.add('active');
    } else {
        // If no event, find the button by text content
        document.querySelectorAll('.tab-btn').forEach(btn => {
            if (btn.textContent.toLowerCase().includes(tab.toLowerCase())) {
                btn.classList.add('active');
            }
        });
    }
    
    // Show the correct tab content
    const tabElement = document.getElementById(`media-${tab}-tab`);
    if (tabElement) {
        tabElement.classList.add('active');
    }
}

// Load media library
async function loadImageLibrary() {
    const mediaGrid = document.getElementById('media-grid');
    mediaGrid.innerHTML = '<div class="loading">Loading images from TikTok...</div>';

    try {
        console.log('🚀 STARTING API REQUEST for get_images...');
        const response = await apiRequest('get_images', {}, 'GET');
        
        console.log('============ API RESPONSE DEBUG ============');
        console.log('📥 Complete API Response:', response);
        console.log('📥 Response type:', typeof response);
        console.log('📥 Response.success:', response.success);
        console.log('📥 Response.data:', response.data);
        console.log('📥 Response.data type:', typeof response.data);
        
        if (response.data) {
            console.log('📥 Response.data.list:', response.data.list);
            console.log('📥 Response.data.list type:', typeof response.data.list);
            console.log('📥 Response.data.list is array:', Array.isArray(response.data.list));
            if (response.data.list) {
                console.log('📥 Response.data.list length:', response.data.list.length);
            }
        }
        console.log('============ END API RESPONSE DEBUG ============');
        
        if (response.success && response.data && response.data.list) {
            const images = response.data.list;
            
            console.log('✅ Processing images array with', images.length, 'items');
            
            if (images.length === 0) {
                mediaGrid.innerHTML = `
                    <div style="grid-column: 1 / -1;">
                        <div style="padding: 15px; background: #f0f8ff; border-radius: 8px; margin-bottom: 15px;">
                            <h4 style="margin: 0 0 10px 0; color: #333;">Manual Image ID Entry</h4>
                            <p style="font-size: 12px; color: #666; margin: 0 0 10px 0;">
                                Enter your image ID from TikTok Ads Manager:
                            </p>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="manual-image-id" placeholder="Enter TikTok Image ID" 
                                       style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <button onclick="useManualImageId()" class="btn-primary" style="padding: 8px 16px;">
                                    Use This Image
                                </button>
                            </div>
                        </div>
                        <div class="empty-state">
                            <p>No images found in TikTok library</p>
                            <small>Upload images to TikTok first or enter an image ID manually above</small>
                        </div>
                    </div>`;
                return;
            }

            console.log(`Loaded ${images.length} images from library`);
            
            // Display images for visual selection
            const imagesHtml = images.map(image => {
                // Create a safe object for selection with the image_id
                const safeImage = {
                    image_id: image.image_id,
                    url: image.url || '',
                    file_name: image.file_name || `Image ${image.image_id}`,
                    type: 'image'
                };
                
                return `
                <div class="media-item" onclick='selectMedia(${JSON.stringify(safeImage)})' data-id="${image.image_id}" 
                     style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;"
                     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)'"
                     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow=''">
                    ${image.url ? 
                        `<img src="${image.url}" alt="${image.file_name || 'Image'}" 
                              style="width: 100%; height: 100%; object-fit: cover;" 
                              onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'media-placeholder\\' style=\\'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;\\'>🖼️</div>'">` : 
                        `<div class="media-placeholder" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); position: relative;">
                            <div class="media-icon">🖼️</div>
                            <div style="position: absolute; bottom: 5px; right: 5px; font-size: 9px; background: rgba(0,0,0,0.5); color: white; padding: 2px 4px; border-radius: 3px;">
                                Image
                            </div>
                        </div>`
                    }
                    <div class="media-info">
                        <div class="media-name" title="${image.file_name || 'Image'}">${(image.file_name || 'Image').substring(0, 20)}${(image.file_name || '').length > 20 ? '...' : ''}</div>
                        <div class="media-type" style="color: #667eea;">Click to Select</div>
                    </div>
                </div>`;
            }).join('');
            
            // Only show manual entry as a small option at the bottom if needed
            const manualInputHtml = `
                <div style="grid-column: 1 / -1; padding: 10px; background: #f9f9f9; border-radius: 6px; margin-top: 15px; border: 1px dashed #ddd;">
                    <details>
                        <summary style="cursor: pointer; font-size: 13px; color: #666;">Can't see your image? Enter ID manually</summary>
                        <div style="margin-top: 10px; display: flex; gap: 10px;">
                            <input type="text" id="manual-image-id" placeholder="Enter TikTok Image ID" 
                                   style="flex: 1; padding: 6px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                            <button onclick="useManualImageId()" class="btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                Use ID
                            </button>
                        </div>
                    </details>
                </div>
            `;
            
            mediaGrid.innerHTML = imagesHtml + (images.length > 0 ? manualInputHtml : '');
            
            if (images.length > 0) {
                showToast(`Found ${images.length} images. Click any image to select it.`, 'success');
            }
        } else {
            mediaGrid.innerHTML = `
                <div class="error">
                    <p>Failed to load images from TikTok</p>
                    <button class="btn-secondary" onclick="loadImageLibrary()">Try Again</button>
                </div>`;
        }
    } catch (error) {
        console.error('Error loading images:', error);
        mediaGrid.innerHTML = `
            <div class="error">
                <p>Error loading images: ${error.message}</p>
                <button class="btn-secondary" onclick="loadImageLibrary()">Try Again</button>
            </div>`;
    }
}

async function loadMediaLibrary() {
    try {
        showToast('Loading media library...', 'info');
        
        const [imagesResponse, videosResponse] = await Promise.all([
            apiRequest('get_images', {}, 'GET'),
            apiRequest('get_videos', {}, 'GET')
        ]);

        state.mediaLibrary = [];

        console.log('GET IMAGES API Response:', imagesResponse);
        
        if (imagesResponse.success && imagesResponse.data && imagesResponse.data.list) {
            console.log('✅ Images loaded:', imagesResponse.data.list.length, imagesResponse.data.list);
            state.mediaLibrary.push(...imagesResponse.data.list.map(img => ({
                ...img,
                type: 'image'
            })));
        } else {
            console.log('❌ Images response failed or empty:', imagesResponse);
            console.log('Images response success:', imagesResponse.success);
            console.log('Images response data:', imagesResponse.data);
            console.log('Images response message:', imagesResponse.message);
        }

        if (videosResponse.success && videosResponse.data && videosResponse.data.list) {
            console.log('Videos loaded:', videosResponse.data.list.length, videosResponse.data.list);
            state.mediaLibrary.push(...videosResponse.data.list.map(vid => ({
                ...vid,
                type: 'video'
            })));
        } else {
            console.log('Videos response failed or empty:', videosResponse);
        }

        console.log('Final media library array:', state.mediaLibrary);
        console.log('Images in library:', state.mediaLibrary.filter(m => m.type === 'image').length);
        console.log('Videos in library:', state.mediaLibrary.filter(m => m.type === 'video').length);
        
        renderMediaGrid();
        updateMediaCount();
        
        if (state.mediaLibrary.length === 0) {
            showToast('No media found. Upload files to add to library.', 'info');
        } else {
            const imageCount = state.mediaLibrary.filter(m => m.type === 'image').length;
            const videoCount = state.mediaLibrary.filter(m => m.type === 'video').length;
            showToast(`Loaded ${state.mediaLibrary.length} media file(s) (${imageCount} images, ${videoCount} videos)`, 'success');
        }
    } catch (error) {
        console.error('Error loading media library:', error);
        showToast('Error loading media library', 'error');
    }
}

// Use video thumbnail as cover image
async function useVideoThumbnail(adIndex) {
    const creativeId = document.getElementById(`creative-id-${adIndex}`).value;
    const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
    
    if (creativeType !== 'video' || !creativeId) {
        showToast('Please select a video first', 'error');
        return;
    }
    
    // Find the video data to get thumbnail URL
    let videoData = null;
    let thumbnailUrl = null;
    
    // Check if we have stored video data
    if (state.selectedVideoData && state.selectedVideoData[creativeId]) {
        videoData = state.selectedVideoData[creativeId];
    } else {
        // Try to find from current media library (if loaded)
        showToast('Loading video information...', 'info');
        
        try {
            const response = await apiRequest('get_videos', {}, 'GET');
            if (response.success && response.data && response.data.list) {
                const video = response.data.list.find(v => v.video_id === creativeId);
                if (video) {
                    videoData = video;
                }
            }
        } catch (error) {
            console.error('Error fetching video data:', error);
        }
    }
    
    // Look for thumbnail URL in multiple possible fields
    if (videoData) {
        thumbnailUrl = videoData.video_cover_url || 
                      videoData.cover_url || 
                      videoData.preview_url || 
                      videoData.thumbnail_url || 
                      videoData.poster_url;
    }
    
    if (!thumbnailUrl) {
        // Try to generate a thumbnail using the video file itself
        showToast('Generating thumbnail from video...', 'info');
        
        try {
            const response = await apiRequest('generate_video_thumbnail', {
                video_id: creativeId
            });
            
            if (response.success && response.data && response.data.thumbnail_url) {
                thumbnailUrl = response.data.thumbnail_url;
            }
        } catch (error) {
            console.error('Error generating thumbnail:', error);
        }
    }
    
    if (!thumbnailUrl) {
        showToast('No thumbnail available for this video. Try uploading a cover image manually.', 'error');
        return;
    }
    
    showLoading('Uploading video thumbnail to TikTok...');
    
    try {
        const response = await apiRequest('upload_thumbnail_as_cover', {
            video_id: creativeId,
            thumbnail_url: thumbnailUrl
        });
        
        if (response.success && response.data && response.data.image_id) {
            const imageId = response.data.image_id;
            
            // Set the cover image ID
            document.getElementById(`cover-image-id-${adIndex}`).value = imageId;
            
            // Update the UI
            const coverPlaceholder = document.getElementById(`cover-placeholder-${adIndex}`);
            const coverContainer = coverPlaceholder.parentElement;
            
            coverContainer.classList.add('has-media');
            coverContainer.style.backgroundImage = `url(${thumbnailUrl})`;
            coverContainer.style.backgroundSize = 'cover';
            coverContainer.style.backgroundPosition = 'center';
            
            coverPlaceholder.innerHTML = `
                <div class="media-selected-info">
                    <div class="media-type-badge">🎬</div>
                    <div class="media-name">Video Thumbnail</div>
                </div>`;
            
            showToast('Video thumbnail uploaded and set as cover image!', 'success');
        } else {
            showToast(response.message || 'Failed to upload thumbnail', 'error');
        }
    } catch (error) {
        console.error('Error uploading thumbnail:', error);
        showToast('Error uploading thumbnail: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Use manually entered image ID
function useManualImageId() {
    const imageIdInput = document.getElementById('manual-image-id');
    if (!imageIdInput) return;
    
    const imageId = imageIdInput.value.trim();
    if (!imageId) {
        showToast('Please enter an image ID', 'error');
        return;
    }
    
    // Create a media object with just the ID
    const manualImage = {
        image_id: imageId,
        url: '',
        file_name: `Manual Image (${imageId})`,
        type: 'image'
    };
    
    // Select this image
    selectMedia(manualImage);
    
    showToast(`Selected image ID: ${imageId}`, 'success');
    
    // Clear the input
    imageIdInput.value = '';
}

// Sync images from TikTok
async function syncImagesFromTikTok() {
    showLoading();
    try {
        const response = await apiRequest('sync_images_from_tiktok');
        
        if (response.success) {
            showToast(response.message || 'Images synced successfully', 'success');
            // Reload the image library
            if (state.currentSelectionType === 'cover') {
                loadImageLibrary();
            }
        } else {
            showToast(response.message || 'Failed to sync images', 'error');
        }
    } catch (error) {
        showToast('Error syncing images: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// Refresh media library
async function refreshMediaLibrary() {
    await loadMediaLibrary();
}

// Sync with TikTok library
async function syncTikTokLibrary() {
    try {
        showToast('Syncing with TikTok library...', 'info');
        
        const response = await apiRequest('sync_tiktok_library', {}, 'POST');
        
        if (response.success) {
            const videoMsg = response.total_videos ? ` (${response.total_videos} videos` : ' (0 videos';
            const imageMsg = response.total_images ? `, ${response.total_images} images)` : ', 0 images)';
            showToast(response.message + videoMsg + imageMsg, 'success');
            // Reload the media library to show new items
            await loadMediaLibrary();
        } else {
            showToast('Failed to sync with TikTok', 'error');
        }
    } catch (error) {
        console.error('Error syncing with TikTok:', error);
        showToast('Error syncing with TikTok library', 'error');
    }
}

// Render media grid
function renderMediaGrid() {
    const grid = document.getElementById('media-grid');
    grid.innerHTML = '';

    const filteredMedia = getFilteredMedia();
    console.log('Rendering media grid with', filteredMedia.length, 'filtered items (', state.mediaLibrary.length, 'total)');

    if (state.mediaLibrary.length === 0) {
        grid.innerHTML = '<p style="text-align: center; color: #999;">No media in library. Upload some files to get started.</p>';
        return;
    }

    if (filteredMedia.length === 0) {
        grid.innerHTML = '<p style="text-align: center; color: #999;">No media matches the current filter.</p>';
        return;
    }

    filteredMedia.forEach((media, index) => {
        console.log(`Rendering media ${index}:`, media.type, media.file_name || 'unnamed');
        const item = document.createElement('div');
        item.className = 'media-item';
        item.dataset.id = media.video_id || media.image_id || media.id;
        item.dataset.type = media.type;
        item.onclick = () => selectMedia(media);

        // Check if this is a newly uploaded file (within last 30 seconds)
        const isNewUpload = state.lastUploadedFile &&
            state.lastUploadedFile.name === media.file_name &&
            state.lastUploadedFile.type === media.type &&
            (Date.now() - state.lastUploadedFile.timestamp) < 30000;

        if (isNewUpload) {
            item.style.animation = 'pulse-highlight 2s ease-in-out';
            item.style.boxShadow = '0 0 15px rgba(76, 175, 80, 0.6)';
        }

        if (media.type === 'image') {
            // Prioritize TikTok's image_url field first, then fallback to other fields
            const imgUrl = media.image_url || media.url || media.preview_url || media.thumbnail_url;
            
            console.log('============ FRONTEND IMAGE DEBUG ============');
            console.log('📷 Processing image media object:', media);
            console.log('🔍 Complete media object structure:', JSON.stringify(media, null, 2));
            
            // Check each URL field individually
            console.log('🌐 URL Fields Analysis:');
            console.log('  media.image_url:', typeof media.image_url, '=', media.image_url);
            console.log('  media.url:', typeof media.url, '=', media.url);
            console.log('  media.preview_url:', typeof media.preview_url, '=', media.preview_url);
            console.log('  media.thumbnail_url:', typeof media.thumbnail_url, '=', media.thumbnail_url);
            
            // Check what imgUrl was selected
            console.log('🎯 URL Selection Logic:');
            if (media.image_url) {
                console.log('  ✅ Using media.image_url:', media.image_url);
            } else if (media.url) {
                console.log('  ⚠️  Falling back to media.url:', media.url);
            } else if (media.preview_url) {
                console.log('  ⚠️  Falling back to media.preview_url:', media.preview_url);
            } else if (media.thumbnail_url) {
                console.log('  ⚠️  Falling back to media.thumbnail_url:', media.thumbnail_url);
            } else {
                console.log('  ❌ NO URL AVAILABLE!');
            }
            
            console.log('🏆 FINAL SELECTED imgUrl:', imgUrl);
            console.log('🏆 imgUrl type:', typeof imgUrl);
            console.log('🏆 imgUrl length:', imgUrl ? imgUrl.length : 'NULL/UNDEFINED');
            console.log('🏆 imgUrl empty check:', imgUrl === '' ? 'EMPTY STRING' : 'NOT EMPTY');
            console.log('============ END FRONTEND IMAGE DEBUG ============');
            
            if (imgUrl && imgUrl !== '') {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; border: 2px solid #4fc3f7; border-radius: 8px; overflow: hidden;">
                        <img src="${imgUrl}" 
                             alt="${media.file_name || 'Image'}" 
                             style="width: 100%; height: 100%; object-fit: cover;"
                             onload="console.log('✅ Image loaded successfully:', '${media.file_name}', 'URL:', '${imgUrl.substring(0, 50)}...')"
                             onerror="console.error('❌ Image failed to load:', '${media.file_name}', 'URL:', '${imgUrl.substring(0, 50)}...'); this.style.display='none'; this.parentElement.innerHTML='<div style=&quot;background: linear-gradient(135deg, #ff5722, #f44336); width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;&quot;><div style=&quot;font-size: 30px;&quot;>❌</div><small>Image Failed</small><br><tiny>${media.file_name || 'Unknown'}</tiny></div>'">
                        <div style="position: absolute; top: 5px; right: 5px; background: #4fc3f7; 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white; font-weight: bold;">
                            📷 IMAGE
                        </div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(79, 195, 247, 0.1); border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <div style="font-weight: 600; font-size: 12px; color: #0277bd;">${media.file_name || 'Image'}</div>
                        <div style="font-size: 10px; color: #0288d1;">${media.width && media.height ? `${media.width}×${media.height}px` : 'Image file'}</div>
                    </div>`;
            } else {
                item.innerHTML = `
                    <div style="width: 100%; height: 150px; background: linear-gradient(135deg, #4fc3f7, #29b6f6); 
                                display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; position: relative;">
                        <div style="font-size: 40px; margin-bottom: 5px;">🖼️</div>
                        <div style="font-size: 12px; font-weight: 600;">${media.file_name || 'Image'}</div>
                        <div style="font-size: 10px; opacity: 0.8; margin-top: 4px;">No preview available</div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 12px;">${media.file_name || 'Image'}</div>
                    </div>`;
            }
        } else {
            // For videos, show preview image or placeholder
            const previewUrl = media.preview_url || media.thumbnail_url || media.poster_url || media.cover_url;
            const videoUrl = media.url || media.video_url;
            
            if (previewUrl && previewUrl !== '') {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; border: 2px solid #667eea; border-radius: 8px; overflow: hidden;">
                        <img src="${previewUrl}" 
                             alt="Video: ${media.file_name || media.video_id}" 
                             style="width: 100%; height: 100%; object-fit: cover;"
                             onerror="this.style.display='none';">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                                    background: rgba(0,0,0,0.6); border-radius: 50%; width: 40px; height: 40px; 
                                    display: flex; align-items: center; justify-content: center;">
                            <span style="color: white; font-size: 18px; margin-left: 2px;">▶</span>
                        </div>
                        <div style="position: absolute; top: 5px; right: 5px; background: #667eea; 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white; font-weight: bold;">
                            🎬 VIDEO
                        </div>
                        <div style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.7); 
                                    padding: 2px 6px; border-radius: 3px; font-size: 10px; color: white;">
                            ${media.duration ? `${Math.round(media.duration)}s` : 'Video'}
                        </div>
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(102, 126, 234, 0.1); border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <div style="font-weight: 600; font-size: 12px; color: #3f51b5;">${media.file_name || 'Video'}</div>
                        <div style="font-size: 10px; color: #5c6bc0;">${media.width && media.height ? `${media.width}×${media.height}px` : 'Video file'} ${media.duration ? `• ${Math.round(media.duration)}s` : ''}</div>
                    </div>`;
            } else if (videoUrl) {
                item.innerHTML = `
                    <div style="position: relative; width: 100%; height: 150px; background: #000;">
                        <video src="${videoUrl}" 
                               style="width: 100%; height: 100%; object-fit: cover;"
                               muted
                               onloadedmetadata="this.currentTime=1"
                               onerror="this.style.display='none'; this.parentElement.classList.add('video-no-preview');"></video>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                                    background: rgba(0,0,0,0.6); border-radius: 50%; width: 40px; height: 40px; 
                                    display: flex; align-items: center; justify-content: center;">
                            <span style="color: white; font-size: 18px; margin-left: 2px;">▶</span>
                        </div>
                    </div>
                    <div class="media-info">
                        <span class="media-name">${media.file_name || 'Video'}</span>
                        ${media.duration ? `<span class="media-duration">${Math.round(media.duration)}s</span>` : ''}
                    </div>`;
            } else {
                // Fallback display for videos without thumbnails
                item.innerHTML = `
                    <div style="width: 100%; height: 150px; background: linear-gradient(135deg, #667eea, #764ba2); 
                                display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; position: relative;">
                        <div style="font-size: 40px; margin-bottom: 5px;">🎬</div>
                        <div style="font-size: 12px; font-weight: 600;">${media.file_name || 'Video'}</div>
                        <div style="font-size: 10px; opacity: 0.8; margin-top: 4px;">Click to generate thumbnail</div>
                        ${media.duration ? `<div style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.5); 
                                                        padding: 2px 6px; border-radius: 3px; font-size: 10px;">
                            ${Math.round(media.duration)}s</div>` : ''}
                    </div>
                    <div class="media-info" style="padding: 5px; background: rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 12px;">${media.file_name || 'Video'}</div>
                    </div>`;
                
                // Add click handler to generate thumbnail
                item.addEventListener('click', async () => {
                    if (media.type === 'video' && !previewUrl) {
                        try {
                            showToast('Generating thumbnail...', 'info');
                            const response = await apiRequest('generate_video_thumbnail', {
                                video_id: media.video_id
                            });
                            
                            if (response.success && response.data && response.data.thumbnail_url) {
                                // Update the media object and re-render
                                media.preview_url = response.data.thumbnail_url;
                                renderMediaGrid();
                                showToast('Thumbnail generated successfully!', 'success');
                            }
                        } catch (error) {
                            console.error('Error generating thumbnail:', error);
                        }
                    }
                });
            }
        }

        // Add file info as tooltip
        const dimensions = (media.width && media.height) ? ` (${media.width}x${media.height})` : '';
        item.title = `${media.file_name || 'Media'}${dimensions}`;

        grid.appendChild(item);
    });
}

// Media filtering functionality
let currentMediaFilter = 'all';

function filterMedia(filterType) {
    currentMediaFilter = filterType;
    
    // Update filter button states
    document.querySelectorAll('.media-filter').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.filter === filterType) {
            btn.classList.add('active');
        }
    });
    
    // Filter and render media
    renderMediaGrid();
    updateMediaCount();
}

function updateMediaCount() {
    const countElement = document.getElementById('media-count');
    if (!countElement) return;
    
    const totalImages = state.mediaLibrary.filter(m => m.type === 'image').length;
    const totalVideos = state.mediaLibrary.filter(m => m.type === 'video').length;
    const visibleItems = getFilteredMedia().length;
    
    let countText = '';
    if (currentMediaFilter === 'all') {
        countText = `Showing ${visibleItems} items (${totalImages} images, ${totalVideos} videos)`;
    } else if (currentMediaFilter === 'image') {
        countText = `Showing ${visibleItems} images`;
    } else if (currentMediaFilter === 'video') {
        countText = `Showing ${visibleItems} videos`;
    }
    
    countElement.textContent = countText;
}

function getFilteredMedia() {
    if (currentMediaFilter === 'all') {
        return state.mediaLibrary;
    }
    return state.mediaLibrary.filter(media => media.type === currentMediaFilter);
}

// Select media from library
function selectMedia(media) {
    const mediaId = media.video_id || media.image_id || media.id;

    // For cover image selection, only allow single selection
    if (state.currentSelectionType === 'cover') {
        // Only allow images for cover selection
        if (media.type !== 'image') {
            showToast('Please select an image for the cover', 'error');
            return;
        }

        // Single selection for cover
        state.selectedMedia = [media];

        // Update UI - clear all selections and select only this one
        document.querySelectorAll('.media-item').forEach(item => {
            item.classList.remove('selected');
        });
        document.querySelector(`.media-item[data-id="${mediaId}"]`)?.classList.add('selected');
    } else {
        // For primary media, allow multiple selection
        const mediaItem = document.querySelector(`.media-item[data-id="${mediaId}"]`);
        const isAlreadySelected = state.selectedMedia.some(m =>
            (m.video_id || m.image_id || m.id) === mediaId
        );

        if (isAlreadySelected) {
            // Deselect if already selected
            state.selectedMedia = state.selectedMedia.filter(m =>
                (m.video_id || m.image_id || m.id) !== mediaId
            );
            mediaItem?.classList.remove('selected');
        } else {
            // Add to selection
            state.selectedMedia.push(media);
            mediaItem?.classList.add('selected');
        }
    }

    // Update selection counter
    updateSelectionCounter();
}

// Update selection counter in modal
function updateSelectionCounter() {
    const counter = document.getElementById('selection-counter');
    if (counter) {
        const count = state.selectedMedia.length;
        counter.textContent = count > 0 ? `${count} selected` : '';
        counter.style.display = count > 0 ? 'inline' : 'none';
    }
}

// Confirm media selection
function confirmMediaSelection() {
    if (!state.selectedMedia || state.selectedMedia.length === 0) {
        showToast('Please select media', 'error');
        return;
    }

    const adIndex = state.currentAdIndex;
    const selectionType = state.currentSelectionType;

    if (selectionType === 'cover') {
        // Handle cover image selection (single selection only)
        const selectedMedia = state.selectedMedia[0];
        if (selectedMedia.type !== 'image') {
            showToast('Please select an image for the cover', 'error');
            return;
        }

        const coverImageId = selectedMedia.image_id;
        document.getElementById(`cover-image-id-${adIndex}`).value = coverImageId;

        // Update cover placeholder
        const coverPlaceholder = document.getElementById(`cover-placeholder-${adIndex}`);
        const coverContainer = coverPlaceholder.parentElement;

        coverContainer.classList.add('has-media');
        if (selectedMedia.url) {
            coverContainer.style.backgroundImage = `url(${selectedMedia.url})`;
            coverContainer.style.backgroundSize = 'cover';
            coverContainer.style.backgroundPosition = 'center';
        }

        coverPlaceholder.innerHTML = `
            <div class="media-selected-info">
                <div class="media-type-badge">🖼️</div>
                <div class="media-name">${selectedMedia.file_name || 'Cover Image'}</div>
            </div>`;

        closeMediaModal();
        showToast('Cover image selected successfully', 'success');

    } else {
        // Handle primary media selection - supports multiple selection
        if (state.selectedMedia.length === 1) {
            // Single selection - update current ad
            const selectedMedia = state.selectedMedia[0];
            updateAdWithMedia(adIndex, selectedMedia);
            closeMediaModal();

            if (selectedMedia.type === 'video') {
                showToast('Video selected. Now select a cover image below.', 'info');
            } else {
                showToast('Media selected successfully', 'success');
            }
        } else {
            // Multiple selection - create ads for each selected media
            const firstMedia = state.selectedMedia[0];
            updateAdWithMedia(adIndex, firstMedia);

            // Create additional ads for remaining selections
            for (let i = 1; i < state.selectedMedia.length; i++) {
                const media = state.selectedMedia[i];
                const newIndex = state.ads.length;
                addAdForm(newIndex);
                state.ads.push({ index: newIndex });
                // Update the newly created ad with the media
                updateAdWithMedia(newIndex, media);
            }

            closeMediaModal();
            showToast(`${state.selectedMedia.length} ads created from selected media`, 'success');
        }
    }
}

// Helper function to update an ad with selected media
function updateAdWithMedia(adIndex, selectedMedia) {
    const mediaId = selectedMedia.video_id || selectedMedia.image_id;
    if (!mediaId) {
        console.error('Invalid media selection - no ID found');
        return;
    }

    document.getElementById(`creative-id-${adIndex}`).value = mediaId;
    document.getElementById(`creative-type-${adIndex}`).value = selectedMedia.type;

    // Store video data for thumbnail access
    if (selectedMedia.type === 'video') {
        if (!state.selectedVideoData) {
            state.selectedVideoData = {};
        }
        state.selectedVideoData[mediaId] = selectedMedia;
    }

    // Update primary media placeholder
    const placeholder = document.getElementById(`creative-placeholder-${adIndex}`);
    const placeholderContainer = placeholder.parentElement;

    placeholderContainer.classList.add('has-media');

    // Show or hide cover image field based on media type
    const coverImageGroup = document.getElementById(`cover-image-group-${adIndex}`);

    if (selectedMedia.type === 'video') {
        // Show cover image field for video
        if (coverImageGroup) {
            coverImageGroup.style.display = 'block';
        }

        const previewUrl = selectedMedia.preview_url || selectedMedia.thumbnail_url || selectedMedia.video_cover_url;
        if (previewUrl) {
            placeholderContainer.style.backgroundImage = `url(${previewUrl})`;
            placeholderContainer.style.backgroundSize = 'cover';
        } else {
            placeholderContainer.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        }

        placeholder.innerHTML = `
            <div class="media-selected-info">
                <div class="media-type-badge">🎥</div>
                <div class="media-name">${selectedMedia.file_name || 'Video'}</div>
                ${selectedMedia.duration ? `<div style="font-size: 11px;">⏱ ${Math.round(selectedMedia.duration)}s</div>` : ''}
            </div>`;

    } else {
        // Hide cover image field for image ads
        if (coverImageGroup) {
            coverImageGroup.style.display = 'none';
        }

        if (selectedMedia.url) {
            placeholderContainer.style.backgroundImage = `url(${selectedMedia.url})`;
            placeholderContainer.style.backgroundSize = 'cover';
        }

        placeholder.innerHTML = `
            <div class="media-selected-info">
                <div class="media-type-badge">📷</div>
                <div class="media-name">${selectedMedia.file_name || 'Image'}</div>
            </div>`;
    }
}

// Handle media upload
async function handleMediaUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const isVideo = file.type.startsWith('video/');
    const isImage = file.type.startsWith('image/');

    if (!isImage && !isVideo) {
        showToast('Please upload an image or video file', 'error');
        return;
    }

    // Check file size
    const maxSize = isVideo ? 500 * 1024 * 1024 : 10 * 1024 * 1024; // 500MB for video, 10MB for image
    if (file.size > maxSize) {
        showToast(`File too large. Maximum size is ${isVideo ? '500MB' : '10MB'}`, 'error');
        return;
    }

    const formData = new FormData();
    formData.append(isVideo ? 'video' : 'image', file);

    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-area').style.display = 'none';
    
    // Add upload status message
    const progressDiv = document.getElementById('upload-progress');
    progressDiv.innerHTML = `<p>Uploading ${file.name}...</p><div class="progress-bar"><div class="progress-fill" style="width: 0%"></div></div>`;

    try {
        addLog('request', `Uploading ${isVideo ? 'video' : 'image'}: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`);
        
        const response = await fetch(`api.php?action=${isVideo ? 'upload_video' : 'upload_image'}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: formData
        });

        // Check if response is ok first
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        // Get response text first to handle empty responses
        const responseText = await response.text();
        
        if (!responseText || responseText.trim() === '') {
            throw new Error('Empty response from server');
        }

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText);
            throw new Error('Invalid JSON response from server');
        }
        
        addLog(result.success ? 'response' : 'error', `Upload ${result.success ? 'successful' : 'failed'}`, result);

        if (result.success) {
            // Show enhanced success message with file details
            const fileName = file.name;
            const fileSize = (file.size / 1024 / 1024).toFixed(2);

            // Update progress area with success message before switching
            progressDiv.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div style="font-size: 50px; margin-bottom: 10px;">✅</div>
                    <p style="font-weight: 600; color: #2e7d32; margin: 0;">Upload Successful!</p>
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 0;">${fileName} (${fileSize} MB)</p>
                </div>
            `;

            showToast(`✅ ${isVideo ? 'Video' : 'Image'} "${fileName}" uploaded successfully!`, 'success');

            // Wait a moment to show success, then reload
            await new Promise(resolve => setTimeout(resolve, 1500));

            // Reload media library to show the new upload
            await loadMediaLibrary();

            // Switch to library tab to show the uploaded file
            document.querySelector('.tab-btn[onclick*="library"]').click();

            // Reset upload form
            event.target.value = '';

            // Store the newly uploaded file info to highlight it
            state.lastUploadedFile = {
                name: fileName,
                type: isVideo ? 'video' : 'image',
                timestamp: Date.now()
            };
        } else {
            const errorMsg = result.message || `Failed to upload ${isVideo ? 'video' : 'image'}`;
            showToast(errorMsg, 'error');
            console.error('Upload error:', result);
        }
    } catch (error) {
        addLog('error', 'Upload failed', { error: error.message });
        showToast('Error uploading file: ' + error.message, 'error');
        console.error('Upload exception:', error);
    } finally {
        document.getElementById('upload-progress').style.display = 'none';
        document.getElementById('upload-area').style.display = 'block';
        progressDiv.innerHTML = '<p>Processing...</p>';
    }
}

// Load identities
async function loadIdentities() {
    try {
        const response = await apiRequest('get_identities', {}, 'GET');
        
        console.log('Identities Response:', response);

        if (response.success && response.data && response.data.list) {
            state.identities = response.data.list;
            console.log('Loaded identities:', state.identities);
            
            // Re-populate all existing ad forms with identities
            state.ads.forEach(ad => {
                populateIdentitiesForAd(ad.index);
            });
        } else {
            console.warn('No identities found in response');
            state.identities = [];
        }
    } catch (error) {
        console.error('Error loading identities:', error);
        state.identities = [];
    }
}

// Load pixels from TikTok account
async function loadPixels() {
    const pixelSelect = document.getElementById('lead-gen-form-id');

    try {
        const response = await apiRequest('get_pixels', {}, 'GET');

        console.log('Pixels API Response:', response);

        // Clear loading state
        pixelSelect.innerHTML = '<option value="">Select a pixel...</option>';

        if (response.success && response.data) {
            // TikTok API might return pixels in different formats
            const pixels = response.data.list || response.data.pixels || [response.data];

            if (pixels && pixels.length > 0) {
                pixels.forEach(pixel => {
                    const option = document.createElement('option');
                    option.value = pixel.pixel_id;  // Use the numeric pixel_id
                    option.textContent = `${pixel.pixel_name || 'Unnamed Pixel'} (${pixel.pixel_code || pixel.pixel_id})`;
                    pixelSelect.appendChild(option);
                });
            } else {
                pixelSelect.innerHTML = '<option value="">No pixels found - Check your account</option>';
            }
        } else {
            console.error('Pixel API failed:', response);
            const errorMsg = response.message || 'No pixels found - Check your account';
            pixelSelect.innerHTML = `<option value="">Error: ${errorMsg}</option>`;
        }
    } catch (error) {
        console.error('Error loading pixels:', error);
        pixelSelect.innerHTML = '<option value="">Error loading pixels</option>';
    }
}

// Populate identities dropdown for an ad
function populateIdentitiesForAd(adIndex) {
    const select = document.getElementById(`identity-${adIndex}`);
    
    // Clear existing options except the first one
    while (select.options.length > 1) {
        select.remove(1);
    }

    if (state.identities && state.identities.length > 0) {
        state.identities.forEach(identity => {
            const option = document.createElement('option');
            option.value = identity.identity_id;
            option.setAttribute('data-identity-type', identity.identity_type || 'CUSTOMIZED_USER');
            
            // Show both identity name and display name if different
            const name = identity.identity_name || identity.display_name || 'Custom Identity';
            const displayName = identity.display_name || '';
            const typeLabel = identity.identity_type === 'TT_USER' ? ' (TikTok)' : ' (Custom)';
            
            if (displayName && displayName !== name) {
                option.textContent = `${name} (${displayName})${typeLabel}`;
            } else {
                option.textContent = name + typeLabel;
            }
            select.appendChild(option);
        });
        
        // Add "Create new custom identity" option
        const createOption = document.createElement('option');
        createOption.value = 'CREATE_NEW';
        createOption.textContent = '+ Create new custom identity';
        createOption.style.fontWeight = 'bold';
        createOption.style.color = '#667eea';
        select.appendChild(createOption);
        
        // Select first identity by default if available
        if (state.identities.length > 0 && select.options.length > 2) {
            select.selectedIndex = 1;
        }
    } else {
        // Add "Create new custom identity" option even when no identities
        const createOption = document.createElement('option');
        createOption.value = 'CREATE_NEW';
        createOption.textContent = '+ Create new custom identity';
        createOption.style.fontWeight = 'bold';
        createOption.style.color = '#667eea';
        select.appendChild(createOption);
        
        // Add helpful messages for no identities
        const option1 = document.createElement('option');
        option1.value = '';
        option1.textContent = '⚠️ No identities found';
        option1.disabled = true;
        select.appendChild(option1);
        
        const option2 = document.createElement('option');
        option2.value = '';
        option2.textContent = '→ Create one above or in TikTok Ads Manager';
        option2.disabled = true;
        select.appendChild(option2);
        
        const option3 = document.createElement('option');
        option3.value = '';
        option3.textContent = '→ Or link a TikTok account';
        option3.disabled = true;
        select.appendChild(option3);
    }
    
    // Add event listener for identity selection change
    select.onchange = function() {
        if (this.value === 'CREATE_NEW') {
            openCreateIdentityModal(adIndex);
        }
    };
}

// Create Identity Modal Functions
let currentIdentityAdIndex = null;

function openCreateIdentityModal(adIndex) {
    currentIdentityAdIndex = adIndex;
    const modal = document.getElementById('create-identity-modal');
    const input = document.getElementById('identity-display-name');
    const charCount = document.getElementById('identity-char-count');
    
    // Reset form
    input.value = '';
    charCount.textContent = '0';
    
    // Add character counter
    input.oninput = function() {
        charCount.textContent = this.value.length;
    };
    
    modal.style.display = 'block';
    input.focus();
    
    // Reset dropdown to first option to avoid confusion
    const select = document.getElementById(`identity-${adIndex}`);
    if (select.options.length > 1) {
        select.selectedIndex = 0;
    }
}

function closeCreateIdentityModal() {
    const modal = document.getElementById('create-identity-modal');
    modal.style.display = 'none';
    currentIdentityAdIndex = null;
}

async function createCustomIdentity() {
    const displayName = document.getElementById('identity-display-name').value.trim();
    const createBtn = document.getElementById('create-identity-btn');
    
    if (!displayName) {
        showToast('Please enter a display name', 'error');
        return;
    }
    
    if (displayName.length > 40) {
        showToast('Display name must be 40 characters or less', 'error');
        return;
    }
    
    createBtn.disabled = true;
    createBtn.textContent = 'Creating...';
    
    try {
        const params = {
            display_name: displayName
        };
        
        // Add image_id if an avatar was selected
        if (window.selectedAvatarImageId) {
            params.image_id = window.selectedAvatarImageId;
        }
        
        const response = await apiRequest('create_identity', params);
        
        if (response.success && response.data && response.data.identity_id) {
            showToast('Custom identity created successfully!', 'success');
            
            // Add new identity to state
            const newIdentity = {
                identity_id: response.data.identity_id,
                display_name: displayName,
                identity_name: displayName,
                identity_type: 'CUSTOMIZED_USER'
            };
            
            if (!state.identities) {
                state.identities = [];
            }
            state.identities.push(newIdentity);
            
            // Refresh the identity dropdown for the current ad
            if (currentIdentityAdIndex !== null) {
                populateIdentitiesForAd(currentIdentityAdIndex);
                
                // Select the newly created identity
                const select = document.getElementById(`identity-${currentIdentityAdIndex}`);
                const newOption = Array.from(select.options).find(option => option.value === newIdentity.identity_id);
                if (newOption) {
                    select.value = newIdentity.identity_id;
                }
            }
            
            // Refresh all other ad identity dropdowns too
            state.ads.forEach(ad => {
                if (ad.index !== currentIdentityAdIndex) {
                    populateIdentitiesForAd(ad.index);
                }
            });
            
            closeCreateIdentityModal();
        } else {
            showToast(response.message || 'Failed to create identity', 'error');
        }
    } catch (error) {
        console.error('Error creating identity:', error);
        showToast('Error creating identity: ' + error.message, 'error');
    } finally {
        createBtn.disabled = false;
        createBtn.textContent = 'Create';
    }
}

// Review ads before publishing
function reviewAds() {
    console.log('=====================================');
    console.log('Review Ads button clicked');
    console.log('Current state:', state);
    console.log('Number of ads:', state.ads.length);
    console.log('Campaign ID:', state.campaignId);
    console.log('Ad Group ID:', state.adGroupId);
    console.log('=====================================');
    
    // Check if we have campaign and ad group
    if (!state.campaignId) {
        showToast('Please create a campaign first (Step 1)', 'error');
        console.error('No campaign ID found');
        return;
    }
    
    if (!state.adGroupId) {
        showToast('Please create an ad group first (Step 2)', 'error');
        console.error('No ad group ID found');
        return;
    }
    
    // Check if we have any ads
    if (state.ads.length === 0) {
        showToast('Please add at least one ad before continuing', 'error');
        console.error('No ads found');
        return;
    }
    
    // Validate all ads
    let allValid = true;

    for (let i = 0; i < state.ads.length; i++) {
        const adIndex = state.ads[i].index;
        console.log(`Validating ad index ${adIndex}`);

        const adNameEl = document.getElementById(`ad-name-${adIndex}`);
        const adTextEl = document.getElementById(`ad-text-${adIndex}`);
        const creativeIdEl = document.getElementById(`creative-id-${adIndex}`);
        const identityEl = document.getElementById(`identity-${adIndex}`);
        const destinationUrlEl = document.getElementById(`destination-url-${adIndex}`);
        
        if (!adNameEl || !adTextEl || !creativeIdEl || !destinationUrlEl) {
            console.error(`Missing form elements for ad ${adIndex}`);
            showToast(`Error: Missing form elements for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        
        const adName = adNameEl.value.trim();
        const adText = adTextEl.value.trim();
        const creativeId = creativeIdEl.value;
        const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
        const coverImageId = document.getElementById(`cover-image-id-${adIndex}`).value;
        const identityId = identityEl ? identityEl.value : '';
        const destinationUrl = destinationUrlEl.value.trim();

        if (!adName) {
            showToast(`Please enter ad name for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        if (!adText) {
            showToast(`Please enter ad copy for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        if (!creativeId) {
            showToast(`Please select media for Ad #${adIndex + 1}`, 'error');
            allValid = false;
            break;
        }
        // Check for cover image on video ads
        if (creativeType === 'video' && !coverImageId) {
            showToast(`Please select a cover image for video Ad #${adIndex + 1}. Cover image is required for video ads.`, 'error');
            allValid = false;
            break;
        }
        // Destination URL is optional for Lead Generation campaigns
        // Identity is REQUIRED according to TikTok API docs
        if (!identityId) {
            showToast(`Please select an identity for Ad #${adIndex + 1}. Identity is required for ad creation.`, 'error');
            allValid = false;
            break;
        }
    }

    if (!allValid) return;

    // Generate review summaries
    generateReviewSummary();

    nextStep();
}

// Generate review summary
function generateReviewSummary() {
    // Campaign summary
    const campaignSummary = document.getElementById('campaign-summary');
    const campaignBudget = document.getElementById('campaign-budget').value;
    
    campaignSummary.innerHTML = `
        <p><strong>Campaign Name:</strong> ${document.getElementById('campaign-name').value}</p>
        <p><strong>Objective:</strong> Lead Generation</p>
        <p><strong>Type:</strong> Manual Campaign</p>
        <p><strong>Campaign Budget:</strong> $${campaignBudget}/day</p>
    `;

    // Ad Group summary
    const adGroupSummary = document.getElementById('adgroup-summary');
    const startDate = new Date(document.getElementById('start-date').value);
    adGroupSummary.innerHTML = `
        <p><strong>Ad Group Name:</strong> ${document.getElementById('adgroup-name').value}</p>
        <p><strong>Daily Budget:</strong> $${document.getElementById('budget').value}</p>
        <p><strong>Start Date:</strong> ${startDate.toLocaleString()}</p>
        <p><strong>Bid Price:</strong> $${document.getElementById('bid-price').value}</p>
        <p><strong>Location:</strong> United States</p>
        <p><strong>Placement:</strong> TikTok</p>
    `;

    // Ads summary
    const adsSummary = document.getElementById('ads-summary');
    adsSummary.innerHTML = '';

    state.ads.forEach(ad => {
        const adIndex = ad.index;
        const adName = document.getElementById(`ad-name-${adIndex}`).value;
        const adText = document.getElementById(`ad-text-${adIndex}`).value;
        const cta = document.getElementById(`cta-${adIndex}`).value;
        const destinationUrl = document.getElementById(`destination-url-${adIndex}`).value;

        const adItem = document.createElement('div');
        adItem.className = 'summary-ad-item';
        adItem.innerHTML = `
            <h4>${adName}</h4>
            <p><strong>Ad Copy:</strong> ${adText.substring(0, 100)}${adText.length > 100 ? '...' : ''}</p>
            <p><strong>CTA:</strong> ${cta.replace(/_/g, ' ')}</p>
            <p><strong>URL:</strong> ${destinationUrl}</p>
        `;

        adsSummary.appendChild(adItem);
    });
}

// Publish all ads
async function publishAll() {
    console.log('=====================================');
    console.log('Publish All button clicked');
    console.log('State before publishing:', state);
    console.log('=====================================');
    
    if (!confirm('Are you sure you want to publish all ads? This cannot be undone.')) {
        console.log('User cancelled publish');
        return;
    }

    console.log('Starting ad creation process...');
    showLoading();

    try {
        const createdAdIds = [];

        // Create all ads
        for (let i = 0; i < state.ads.length; i++) {
            const adIndex = state.ads[i].index;

            const identitySelect = document.getElementById(`identity-${adIndex}`);
            const selectedIdentity = identitySelect.value;
            const selectedOption = identitySelect.options[identitySelect.selectedIndex];
            const identityType = selectedOption ? selectedOption.getAttribute('data-identity-type') : 'CUSTOMIZED_USER';

            // Check if using dynamic CTA
            const ctaMode = document.querySelector(`input[name="cta-mode-${adIndex}"]:checked`)?.value || 'static';
            const dynamicCTAPortfolio = document.getElementById(`dynamic-cta-portfolio-${adIndex}`)?.value;

            const adData = {
                adgroup_id: state.adGroupId,
                ad_name: document.getElementById(`ad-name-${adIndex}`).value,
                ad_text: document.getElementById(`ad-text-${adIndex}`).value,
                landing_page_url: document.getElementById(`destination-url-${adIndex}`).value,
                identity_id: selectedIdentity,
                identity_type: identityType || 'CUSTOMIZED_USER',
                promotion_type: 'WEBSITE'  // Using WEBSITE for Lead Gen campaigns with landing pages
            };

            // Add CTA based on mode
            if (ctaMode === 'dynamic' && dynamicCTAPortfolio) {
                adData.call_to_action_id = dynamicCTAPortfolio;
                console.log(`Using dynamic CTA portfolio ID: ${dynamicCTAPortfolio}`);
            } else {
                adData.call_to_action = document.getElementById(`cta-${adIndex}`).value;
                console.log(`Using static CTA: ${adData.call_to_action}`);
            }

            const creativeType = document.getElementById(`creative-type-${adIndex}`).value;
            const creativeId = document.getElementById(`creative-id-${adIndex}`).value;
            const coverImageId = document.getElementById(`cover-image-id-${adIndex}`)?.value;

            if (creativeType === 'video') {
                adData.video_id = creativeId;
                adData.ad_format = 'SINGLE_VIDEO';
                
                // For video ads, image_ids is REQUIRED for the video cover
                if (coverImageId) {
                    adData.image_ids = [coverImageId];
                    console.log(`Using cover image_id for video ad: ${coverImageId}`);
                } else {
                    console.warn('No cover image_id for video ad - this may cause the ad creation to fail');
                    // Try to use a default or placeholder image_id if available
                    adData.image_ids = []; // This will likely fail, but TikTok will provide error details
                }
            } else {
                adData.image_ids = [creativeId];
                adData.ad_format = 'SINGLE_IMAGE';
            }

            console.log(`Creating ad ${i+1}/${state.ads.length}:`, adData);
            const response = await apiRequest('create_ad', adData);
            console.log(`Ad creation response:`, response);

            if (response.success && response.data && response.data.ad_ids && response.data.ad_ids.length > 0) {
                createdAdIds.push(...response.data.ad_ids);
                showToast(`Ad ${i+1} created successfully`, 'success');
            } else {
                console.error('Ad creation failed:', response);
                throw new Error(`Failed to create ad ${i+1}: ${response.message || 'Unknown error'}`);
            }
        }

        // Ads are created with ENABLE status by default, so they're already published
        if (createdAdIds.length > 0) {
            showToast('All ads created and published successfully! 🎉', 'success');
            
            // Log success
            console.log(`Successfully created ${createdAdIds.length} ads:`, createdAdIds);
            
            // Optional: Try to explicitly enable ads (but continue even if this fails)
            try {
                const publishResponse = await apiRequest('publish_ads', {
                    ad_ids: createdAdIds
                });
                
                if (!publishResponse.success) {
                    console.log('Note: Ads are already enabled by default. Status update not required.');
                }
            } catch (e) {
                // This is not critical - ads are already enabled by default
                console.log('Status update skipped - ads are enabled by default');
            }

            // Show success modal
            setTimeout(() => {
                showSuccessModal();
            }, 1500);
        }
    } catch (error) {
        showToast('Error publishing ads: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

// API request helper
async function apiRequest(action, data = {}, method = 'POST') {
    const url = `api.php?action=${action}`;

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        }
    };

    if (method === 'POST') {
        options.body = JSON.stringify(data);
    }

    // Log the request
    addLog('request', `${method} ${action}`, method === 'POST' ? data : null);

    try {
        const response = await fetch(url, options);

        if (!response.ok) {
            addLog('error', `HTTP ${response.status} error for ${action}`);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const jsonResponse = await response.json();

        // Log the response
        if (jsonResponse.success === false) {
            addLog('error', `API Error: ${action}`, jsonResponse);
        } else {
            addLog('response', `${action} completed`, jsonResponse);
        }

        return jsonResponse;
    } catch (error) {
        addLog('error', `Request failed: ${error.message}`, { action, error: error.message });
        throw error;
    }
}

// Logout
async function logout() {
    if (confirm('Are you sure you want to logout?')) {
        await apiRequest('logout');
        window.location.href = 'index.php';
    }
}

// Show loading overlay
function showLoading() {
    document.getElementById('loading-overlay').classList.add('show');
}

// Hide loading overlay
function hideLoading() {
    document.getElementById('loading-overlay').classList.remove('show');
}

// Show toast notification
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast show ${type}`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Toggle between pixel dropdown and manual input
function togglePixelInput() {
    const pixelMethod = document.querySelector('input[name="pixel-method"]:checked')?.value;
    const dropdownContainer = document.getElementById('pixel-dropdown-container');
    const manualContainer = document.getElementById('pixel-manual-container');

    console.log('Toggle pixel input - Method:', pixelMethod);

    if (!dropdownContainer || !manualContainer) {
        console.error('Pixel containers not found');
        return;
    }

    if (pixelMethod === 'manual') {
        dropdownContainer.style.display = 'none';
        manualContainer.style.display = 'block';
        console.log('Showing manual input');
    } else {
        dropdownContainer.style.display = 'block';
        manualContainer.style.display = 'none';
        console.log('Showing dropdown');
    }
}

// Show success modal with thank you message
function showSuccessModal() {
    // Create modal overlay
    const modalHtml = `
        <div id="success-modal" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        ">
            <div style="
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                animation: slideIn 0.3s ease;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            ">
                <div style="font-size: 72px; margin-bottom: 20px;">🎉</div>
                <h2 style="color: #10b981; margin-bottom: 10px; font-size: 32px;">Thank You!</h2>
                <p style="color: #333; margin-bottom: 20px; font-size: 18px; font-weight: 500;">
                    Campaign Launched Successfully!
                </p>
                <p style="color: #666; margin-bottom: 30px; font-size: 14px;">
                    Your TikTok ad campaign has been created and is now live. It may take a few minutes for the campaign to appear in your TikTok Ads Manager.
                </p>
                <p style="color: #666; margin-bottom: 30px; font-size: 14px; font-weight: 600;">
                    Would you like to create another campaign?
                </p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="createNewCampaign()" style="
                        background: #1a1a1a;
                        color: white;
                        border: 2px solid #1a1a1a;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        box-shadow: 0 4px 15px rgba(26, 26, 26, 0.3);
                        transition: all 0.3s;
                    " onmouseover="this.style.background='#2d2d2d'; this.style.transform='translateY(-2px)'" onmouseout="this.style.background='#1a1a1a'; this.style.transform='translateY(0)'">
                        Yes, Create Another
                    </button>
                    <button onclick="finishAndReset()" style="
                        background: #f3f4f6;
                        color: #374151;
                        border: 2px solid #e5e7eb;
                        padding: 14px 35px;
                        border-radius: 8px;
                        font-size: 16px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                        No, Go to Home
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Add CSS animation if not already added
    if (!document.getElementById('success-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'success-modal-styles';
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from { 
                    transform: translateY(-30px); 
                    opacity: 0; 
                }
                to { 
                    transform: translateY(0); 
                    opacity: 1; 
                }
            }
        `;
        document.head.appendChild(style);
    }
}

// Create new campaign - reload page
function createNewCampaign() {
    location.reload();
}

// Finish and redirect to advertiser selection page
function finishAndReset() {
    // Remove success modal
    const modal = document.getElementById('success-modal');
    if (modal) {
        modal.remove();
    }
    
    // Redirect to advertiser selection page (home)
    window.location.href = 'select-advertiser.php';
}

// Age targeting functions
function selectAllAges() {
    const checkboxes = document.querySelectorAll('.age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

function clearAllAges() {
    const checkboxes = document.querySelectorAll('.age-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

function selectDefaultAges() {
    // Clear all first
    clearAllAges();
    
    // Select default ages (18+ excluding 13-17)
    const defaultAges = ['AGE_18_24', 'AGE_25_34', 'AGE_35_44', 'AGE_45_54', 'AGE_55_100'];
    defaultAges.forEach(age => {
        const checkbox = document.querySelector(`.age-checkbox[value="${age}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
}

function getSelectedAgeGroups() {
    const selectedAges = [];
    const checkboxes = document.querySelectorAll('.age-checkbox:checked');
    
    checkboxes.forEach(checkbox => {
        selectedAges.push(checkbox.value);
    });
    
    return selectedAges;
}

// Location targeting functions

// US States data with location IDs
const US_STATES = [
    { name: 'Alabama', id: '4829764' },
    { name: 'Alaska', id: '5879092' },
    { name: 'Arizona', id: '5551752' },
    { name: 'Arkansas', id: '4099753' },
    { name: 'California', id: '5332921' },
    { name: 'Colorado', id: '5417618' },
    { name: 'Connecticut', id: '4831725' },
    { name: 'Delaware', id: '4142224' },
    { name: 'Florida', id: '4155751' },
    { name: 'Georgia', id: '4197000' },
    { name: 'Hawaii', id: '5855797' },
    { name: 'Idaho', id: '5596512' },
    { name: 'Illinois', id: '4896861' },
    { name: 'Indiana', id: '4921868' },
    { name: 'Iowa', id: '4862182' },
    { name: 'Kansas', id: '4273857' },
    { name: 'Kentucky', id: '6254925' },
    { name: 'Louisiana', id: '4331987' },
    { name: 'Maine', id: '4971068' },
    { name: 'Maryland', id: '4361885' },
    { name: 'Massachusetts', id: '6254926' },
    { name: 'Michigan', id: '5001836' },
    { name: 'Minnesota', id: '5037779' },
    { name: 'Mississippi', id: '4436296' },
    { name: 'Missouri', id: '4398678' },
    { name: 'Montana', id: '5667009' },
    { name: 'Nebraska', id: '5073708' },
    { name: 'Nevada', id: '5509151' },
    { name: 'New Hampshire', id: '5090174' },
    { name: 'New Jersey', id: '5101760' },
    { name: 'New Mexico', id: '5481136' },
    { name: 'New York', id: '5128638' },
    { name: 'North Carolina', id: '4482348' },
    { name: 'North Dakota', id: '5690763' },
    { name: 'Ohio', id: '5165418' },
    { name: 'Oklahoma', id: '4544379' },
    { name: 'Oregon', id: '5744337' },
    { name: 'Pennsylvania', id: '6254927' },
    { name: 'Rhode Island', id: '5224323' },
    { name: 'South Carolina', id: '4597040' },
    { name: 'South Dakota', id: '5769223' },
    { name: 'Tennessee', id: '4662168' },
    { name: 'Texas', id: '4736286' },
    { name: 'Utah', id: '5549030' },
    { name: 'Vermont', id: '5242283' },
    { name: 'Virginia', id: '6254928' },
    { name: 'Washington', id: '5815135' },
    { name: 'West Virginia', id: '4826850' },
    { name: 'Wisconsin', id: '5279468' },
    { name: 'Wyoming', id: '5843591' },
    { name: 'District of Columbia', id: '4138106' }
];

const POPULAR_STATES = ['California', 'Texas', 'New York', 'Florida', 'Illinois', 'Pennsylvania', 'Ohio', 'Georgia', 'North Carolina', 'Michigan'];

function toggleLocationMethod() {
    const methodElement = document.querySelector('input[name="location_method"]:checked');
    if (!methodElement) {
        console.error('No location method radio button found');
        return;
    }
    
    const method = methodElement.value;
    const countryOption = document.getElementById('country-targeting');
    const statesOption = document.getElementById('states-targeting');
    
    if (!countryOption || !statesOption) {
        console.error('Location targeting elements not found');
        return;
    }
    
    console.log('Toggling location method to:', method);
    
    if (method === 'country') {
        countryOption.style.display = 'block';
        countryOption.classList.add('active');
        statesOption.style.display = 'none';
        statesOption.classList.remove('active');
    } else if (method === 'states') {
        countryOption.style.display = 'none';
        countryOption.classList.remove('active');
        statesOption.style.display = 'block';
        statesOption.classList.add('active');
        
        // Initialize states grid if not already done
        populateStatesGrid();
    }
}








// Initialize location targeting on page load
function initializeLocationTargeting() {
    // Ensure the default country option is displayed
    const countryRadio = document.querySelector('input[name="location_method"][value="country"]');
    if (countryRadio) {
        countryRadio.checked = true;
        toggleLocationMethod();
        console.log('Location targeting initialized - default to country');
    }
    
    // Auto-populate states grid when states option is available
    const statesRadio = document.querySelector('input[name="location_method"][value="states"]');
    if (statesRadio) {
        // Pre-populate the grid so it's ready when user switches to states
        populateStatesGrid();
    }
}

function getSelectedLocationIds() {
    const methodElement = document.querySelector('input[name="location_method"]:checked');
    
    // If no radio button is found or checked, default to country
    if (!methodElement) {
        console.log('No location method selected, defaulting to country');
        return ['6252001']; // Default to United States
    }
    
    const method = methodElement.value;
    console.log('Selected location method:', method);
    
    if (method === 'country') {
        return ['6252001']; // United States
    } else if (method === 'states') {
        // Get selected state checkboxes
        const selectedCheckboxes = document.querySelectorAll('input[name="state_selection"]:checked');
        
        if (selectedCheckboxes.length === 0) {
            console.log('States method selected but no states selected');
            return null; // Error case - will be handled by validation
        }
        
        const locations = Array.from(selectedCheckboxes).map(checkbox => checkbox.value);
        console.log('Returning selected state location IDs:', locations);
        return locations;
    }
    
    return ['6252001']; // Fallback to US
}

// State selection functions
function populateStatesGrid() {
    const statesGrid = document.getElementById('states-grid');
    if (!statesGrid) {
        console.error('States grid element not found');
        return;
    }
    
    // Only populate if not already done
    if (statesGrid.children.length > 0) {
        return;
    }
    
    console.log('Populating states grid with', US_STATES.length, 'states');
    
    US_STATES.forEach(state => {
        const stateItem = document.createElement('div');
        stateItem.className = 'state-item';
        
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'state_selection';
        checkbox.value = state.id;
        checkbox.id = `state_${state.id}`;
        checkbox.checked = true; // All states selected by default
        checkbox.addEventListener('change', updateStatesCount);
        
        const label = document.createElement('label');
        label.htmlFor = `state_${state.id}`;
        label.textContent = state.name;
        
        stateItem.appendChild(checkbox);
        stateItem.appendChild(label);
        statesGrid.appendChild(stateItem);
    });
    
    updateStatesCount();
}

function selectAllStates() {
    const checkboxes = document.querySelectorAll('input[name="state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateStatesCount();
    console.log('All states selected');
}

function clearAllStates() {
    const checkboxes = document.querySelectorAll('input[name="state_selection"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateStatesCount();
    console.log('All states cleared');
}

function selectPopularStates() {
    // First clear all
    clearAllStates();
    
    // Then select popular states
    POPULAR_STATES.forEach(stateName => {
        const state = US_STATES.find(s => s.name === stateName);
        if (state) {
            const checkbox = document.getElementById(`state_${state.id}`);
            if (checkbox) {
                checkbox.checked = true;
            }
        }
    });
    updateStatesCount();
    console.log('Popular states selected');
}

function updateStatesCount() {
    const selectedCheckboxes = document.querySelectorAll('input[name="state_selection"]:checked');
    const countElement = document.getElementById('selected-states-count');
    if (countElement) {
        countElement.textContent = selectedCheckboxes.length;
    }
    console.log('States count updated:', selectedCheckboxes.length);
}

// Avatar Selection Functions
let selectedAvatarImageId = null;

function selectIdentityAvatar() {
    const modal = document.getElementById('avatar-selection-modal');
    modal.style.display = 'block';
    
    // Load TikTok library images
    loadAvatarLibrary();
}

function closeAvatarSelectionModal() {
    const modal = document.getElementById('avatar-selection-modal');
    modal.style.display = 'none';
    selectedAvatarImageId = null;
}

function switchAvatarTab(tab, event) {
    // Remove active class from all tabs
    document.querySelectorAll('#avatar-selection-modal .tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Hide all tab contents
    document.querySelectorAll('#avatar-selection-modal .media-tab').forEach(content => {
        content.style.display = 'none';
    });
    
    // Activate clicked tab
    event.target.classList.add('active');
    
    // Show corresponding content
    if (tab === 'library') {
        document.getElementById('avatar-library-tab').style.display = 'block';
    } else if (tab === 'upload') {
        document.getElementById('avatar-upload-tab').style.display = 'block';
    }
}

async function loadAvatarLibrary() {
    try {
        const response = await apiRequest('get_images', {}, 'GET');
        const grid = document.getElementById('avatar-library-grid');
        grid.innerHTML = '';
        
        if (response.success && response.data && response.data.list && response.data.list.length > 0) {
            // Show all images for avatar selection (will be auto-cropped if not square)
            response.data.list.forEach(image => {
                    const item = document.createElement('div');
                    item.className = 'media-item avatar-item';
                    item.style.cursor = 'pointer';
                    item.onclick = () => selectAvatarImage(image);
                    
                    // Create image preview
                    const imgUrl = image.url || image.image_url || image.preview_url || image.thumbnail_url;
                    if (imgUrl && imgUrl !== '') {
                        item.innerHTML = `
                            <div style="position: relative; width: 100%; height: 120px;">
                                <img src="${imgUrl}" alt="${image.file_name || 'Image'}" 
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;"
                                     onload="console.log('Avatar image loaded:', '${imgUrl}')"
                                     onerror="this.parentNode.innerHTML='<div style=&quot;width: 100%; height: 100%; background: linear-gradient(135deg, #4fc3f7, #29b6f6); display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;&quot;><div style=&quot;font-size: 30px; margin-bottom: 5px;&quot;>🖼️</div><div style=&quot;font-size: 10px; text-align: center; padding: 0 5px;&quot;>${image.file_name || 'Image'}</div></div>'">
                                <div style="position: absolute; bottom: 2px; left: 2px; background: rgba(0,0,0,0.7); color: white; padding: 2px 4px; font-size: 10px; border-radius: 2px;">
                                    ${image.width}x${image.height}
                                </div>
                            </div>
                        `;
                    } else {
                        item.innerHTML = `
                            <div style="position: relative; width: 100%; height: 120px;">
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #4fc3f7, #29b6f6); 
                                            display: flex; flex-direction: column; align-items: center; justify-content: center; color: white;">
                                    <div style="font-size: 30px; margin-bottom: 5px;">🖼️</div>
                                    <div style="font-size: 10px; text-align: center; padding: 0 5px;">${image.file_name || 'Image'}</div>
                                </div>
                            </div>
                        `;
                    }
                    
                    grid.appendChild(item);
                });
        } else {
            grid.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">No images in TikTok library. Upload some images first or sync from TikTok.</p>';
        }
    } catch (error) {
        console.error('Error loading avatar library:', error);
        document.getElementById('avatar-library-grid').innerHTML = '<p style="text-align: center; color: #f00; padding: 20px;">Error loading images.</p>';
    }
}

async function selectAvatarImage(image) {
    // Remove selection from other items
    document.querySelectorAll('.avatar-item').forEach(item => {
        item.style.border = 'none';
        item.style.boxShadow = 'none';
    });
    
    // Highlight selected item
    event.target.closest('.avatar-item').style.border = '3px solid #667eea';
    event.target.closest('.avatar-item').style.boxShadow = '0 0 10px rgba(102, 126, 234, 0.3)';
    
    console.log('🖼️ Image selected:', image.image_id, 'Dimensions:', image.width + 'x' + image.height);
    
    // Check if image needs cropping for avatar use
    if (image.width && image.height && image.width !== image.height) {
        showToast(`Creating square version of ${image.width}x${image.height} image...`, 'info');
        console.log('🔄 Non-square image detected, creating cropped version...');
        
        try {
            // Auto-crop and upload the image
            const cropResponse = await apiRequest('auto_crop_and_upload', {
                image_id: image.image_id,
                image_url: image.image_url || image.url || image.preview_url || image.thumbnail_url,
                file_name: image.file_name || 'avatar_image'
            });
            
            if (cropResponse.success) {
                const dimensions = `${cropResponse.width}x${cropResponse.height}`;
                showToast(`✅ Square version (${dimensions}) created and uploaded to TikTok!`, 'success');
                console.log('✅ Cropped image uploaded:', cropResponse.image_id, 'Dimensions:', dimensions);
                
                // Use the cropped image ID
                selectedAvatarImageId = cropResponse.image_id;
                
                // Reload the avatar library to show the new cropped image
                setTimeout(() => {
                    loadAvatarLibrary();
                }, 1500);
                
                // Enable confirm button
                document.getElementById('confirm-avatar-btn').disabled = false;
            } else {
                throw new Error(cropResponse.message || 'Failed to create cropped version');
            }
        } catch (error) {
            console.error('❌ Error creating cropped version:', error);
            showToast('Failed to create square version. You can still use the original image.', 'warning');
            
            // Allow user to continue with original image
            selectedAvatarImageId = image.image_id;
            document.getElementById('confirm-avatar-btn').disabled = false;
        }
    } else if (image.width && image.height) {
        showToast(`Perfect! Selected square image (${image.width}x${image.height}) ready for avatar use`, 'success');
        selectedAvatarImageId = image.image_id;
        console.log('✅ Square image ready. ID:', image.image_id);
        document.getElementById('confirm-avatar-btn').disabled = false;
    } else {
        // Unknown dimensions, assume it's safe to use
        console.log('⚠️ Unknown dimensions, using original image ID:', image.image_id);
        selectedAvatarImageId = image.image_id;
        document.getElementById('confirm-avatar-btn').disabled = false;
    }
}

function handleAvatarUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate file type
    if (!file.type.startsWith('image/')) {
        showToast('Please select an image file', 'error');
        return;
    }
    
    // Automatically crop image to square for avatar use
    const img = new Image();
    const reader = new FileReader();
    
    reader.onload = function(e) {
        img.onload = function() {
            // Create canvas to crop image to square
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Determine the crop size (use the smaller dimension)
            const cropSize = Math.min(img.width, img.height);
            canvas.width = cropSize;
            canvas.height = cropSize;
            
            // Calculate crop position (center crop)
            const offsetX = (img.width - cropSize) / 2;
            const offsetY = (img.height - cropSize) / 2;
            
            // Draw the cropped image
            ctx.drawImage(img, offsetX, offsetY, cropSize, cropSize, 0, 0, cropSize, cropSize);
            
            // Convert canvas to blob and show preview
            canvas.toBlob(function(blob) {
                const croppedUrl = URL.createObjectURL(blob);
                document.getElementById('avatar-preview-img').src = croppedUrl;
                document.getElementById('avatar-upload-preview').style.display = 'block';
                
                // Store the cropped blob for upload
                window.croppedAvatarBlob = blob;
                
                if (img.width !== img.height) {
                    showToast(`Image automatically cropped to square (${cropSize}x${cropSize}) for avatar use`, 'info');
                } else {
                    showToast('Avatar image ready for upload', 'success');
                }
            }, file.type, 0.9);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

async function uploadAvatarImage() {
    const fileInput = document.getElementById('avatar-file-input');
    const originalFile = fileInput.files[0];
    
    if (!originalFile) {
        showToast('Please select an image file', 'error');
        return;
    }
    
    // Use cropped blob if available, otherwise use original file
    const fileToUpload = window.croppedAvatarBlob || originalFile;
    const fileName = originalFile.name.replace(/\.[^/.]+$/, '') + '_avatar.jpg'; // Add avatar suffix
    
    try {
        showToast('Uploading avatar image...', 'info');
        
        const formData = new FormData();
        formData.append('image', fileToUpload, fileName);
        formData.append('action', 'upload_image');
        
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data && result.data.image_id) {
            selectedAvatarImageId = result.data.image_id;
            showToast('Avatar image uploaded successfully', 'success');
            
            // Enable confirm button
            document.getElementById('confirm-avatar-btn').disabled = false;
        } else {
            showToast('Failed to upload avatar image: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error uploading avatar:', error);
        showToast('Error uploading avatar image', 'error');
    }
}

function confirmAvatarSelection() {
    if (!selectedAvatarImageId) {
        showToast('Please select or upload an avatar image', 'error');
        return;
    }
    
    // Update the preview in the identity modal
    const previewImg = document.getElementById('identity-avatar-img');
    if (previewImg) {
        // For now, just show a placeholder since we have the image_id
        previewImg.style.display = 'none';
        previewImg.parentElement.innerHTML = `
            <div style="background: linear-gradient(135deg, #667eea, #764ba2); width: 100%; height: 100%; 
                        display: flex; align-items: center; justify-content: center; color: white; 
                        font-size: 24px; font-weight: bold; border-radius: 50%;">✓</div>
        `;
    }
    
    // Store the selected image ID for identity creation
    window.selectedAvatarImageId = selectedAvatarImageId;
    
    closeAvatarSelectionModal();
    showToast('Avatar image selected', 'success');
}
