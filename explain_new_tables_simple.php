<?php
// SIMPLIFIED EXPLANATION: New Appointment-Referral System Tables
require_once 'config/db.php';

echo "<h1>SIMPLIFIED EXPLANATION: New Appointment System Tables</h1>\n";
echo "<p><em>Breaking down the tables and data flow in simple terms</em></p>\n";

echo "<hr>";
echo "<h2>1. APPOINTMENT_REFERRALS Table (Main Table)</h2>\n";
echo "<h3>Purpose: Combines referral + appointment in ONE record</h3>\n";

echo "<h4>🔹 CORE DATA (Required, Fixed after creation):</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>referral_number</td><td>Unique referral code</td><td>'REF-2025-001'</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>patient_id</td><td>Who is being referred</td><td>12345</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>referred_by_employee_id</td><td>Staff who created referral</td><td>Employee #67</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>referral_reason</td><td>Why patient needs appointment</td><td>'Chest pain'</td><td>✅ Can edit</td></tr>\n";
echo "<tr><td>diagnosis</td><td>Initial diagnosis</td><td>'Possible hypertension'</td><td>✅ Can edit</td></tr>\n";
echo "</table>\n";

echo "<h4>🔹 APPOINTMENT DATA (Required, but can be rescheduled):</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>assigned_doctor_id</td><td>Which doctor will see patient</td><td>Dr. Santos (ID: 15)</td><td>🔄 Reassignable</td></tr>\n";
echo "<tr><td>appointment_date</td><td>When appointment is scheduled</td><td>'2025-11-15'</td><td>🔄 Reschedulable</td></tr>\n";
echo "<tr><td>appointment_time</td><td>What time appointment starts</td><td>'10:30:00'</td><td>🔄 Reschedulable</td></tr>\n";
echo "<tr><td>appointment_status</td><td>Current stage</td><td>'scheduled' → 'completed'</td><td>🔄 Updates automatically</td></tr>\n";
echo "</table>\n";

echo "<h4>🔹 TRACKING DATA (System managed):</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>checked_in_at</td><td>When patient arrived</td><td>'2025-11-15 10:25:00'</td><td>🤖 System sets once</td></tr>\n";
echo "<tr><td>appointment_completed_at</td><td>When consultation finished</td><td>'2025-11-15 11:00:00'</td><td>🤖 System sets once</td></tr>\n";
echo "<tr><td>created_at</td><td>When referral was made</td><td>'2025-11-10 14:30:00'</td><td>❌ Never changes</td></tr>\n";
echo "</table>\n";

echo "<h4>💡 DATA FLOW EXAMPLE:</h4>\n";
echo "<div style='background: #e8f5e8; padding: 15px; border: 1px solid #4CAF50; margin: 10px 0;'>\n";
echo "<strong>Step 1:</strong> Nurse creates referral → Record created with patient info + medical reason<br>\n";
echo "<strong>Step 2:</strong> System shows available doctor slots → Nurse assigns doctor + date/time<br>\n";
echo "<strong>Step 3:</strong> Patient receives referral with appointment details<br>\n";
echo "<strong>Step 4:</strong> Patient arrives → System updates 'checked_in_at'<br>\n";
echo "<strong>Step 5:</strong> Doctor finishes consultation → System updates 'appointment_completed_at'<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<h2>2. DOCTOR_SCHEDULES_V2 Table (Doctor Working Hours)</h2>\n";
echo "<h3>Purpose: Stores when each doctor is available to work</h3>\n";

echo "<h4>🔹 SIMPLE STRUCTURE:</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>doctor_id</td><td>Which doctor</td><td>Dr. Santos (ID: 15)</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>day_of_week</td><td>What day</td><td>'monday'</td><td>❌ Fixed per record</td></tr>\n";
echo "<tr><td>start_time</td><td>When doctor starts work</td><td>'08:00:00'</td><td>✅ Can update</td></tr>\n";
echo "<tr><td>end_time</td><td>When doctor finishes work</td><td>'17:00:00'</td><td>✅ Can update</td></tr>\n";
echo "<tr><td>appointment_duration</td><td>Minutes per patient</td><td>30 minutes</td><td>✅ Can adjust</td></tr>\n";
echo "<tr><td>is_active</td><td>Still using this schedule?</td><td>TRUE/FALSE</td><td>✅ Can disable</td></tr>\n";
echo "</table>\n";

echo "<h4>💡 REAL WORLD EXAMPLE:</h4>\n";
echo "<div style='background: #e3f2fd; padding: 15px; border: 1px solid #2196F3; margin: 10px 0;'>\n";
echo "<strong>Dr. Santos Monday Schedule:</strong><br>\n";
echo "• Works: 8:00 AM to 5:00 PM<br>\n";
echo "• Each appointment: 30 minutes<br>\n";
echo "• Lunch break: 12:00 PM to 1:00 PM<br>\n";
echo "• Available slots: 8:00, 8:30, 9:00, 9:30... (skip lunch) ...1:30, 2:00, 2:30, etc.<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<h2>3. DOCTOR_SCHEDULE_SLOTS_V2 Table (Available Time Slots)</h2>\n";
echo "<h3>Purpose: Specific time slots that can be booked</h3>\n";

echo "<h4>🔹 AUTO-GENERATED FROM SCHEDULES:</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>doctor_id</td><td>Which doctor</td><td>Dr. Santos (ID: 15)</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>slot_date</td><td>Specific date</td><td>'2025-11-15'</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>slot_time</td><td>Specific time</td><td>'10:30:00'</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>is_available</td><td>Can be booked?</td><td>TRUE/FALSE</td><td>🔄 Changes when booked</td></tr>\n";
echo "<tr><td>is_booked</td><td>Someone has this slot?</td><td>TRUE/FALSE</td><td>🔄 Changes when booked</td></tr>\n";
echo "</table>\n";

echo "<h4>💡 HOW SLOTS ARE CREATED:</h4>\n";
echo "<div style='background: #fff3e0; padding: 15px; border: 1px solid #FF9800; margin: 10px 0;'>\n";
echo "<strong>System automatically creates slots:</strong><br>\n";
echo "• Reads Dr. Santos works Monday 8:00 AM - 5:00 PM, 30-min appointments<br>\n";
echo "• Creates slots: Monday Nov 15: 8:00, 8:30, 9:00, 9:30, 10:00, 10:30...<br>\n";
echo "• When patient books 10:30 slot → is_available = FALSE, is_booked = TRUE<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<h2>4. DOCTOR_AVAILABILITY_EXCEPTIONS_V2 Table (Special Cases)</h2>\n";
echo "<h3>Purpose: Handle when doctor is not available (sick leave, holidays)</h3>\n";

echo "<h4>🔹 EXCEPTION HANDLING:</h4>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Column</th><th>What It Stores</th><th>Example</th><th>Can Change?</th></tr>\n";
echo "<tr><td>doctor_id</td><td>Which doctor</td><td>Dr. Santos (ID: 15)</td><td>❌ Fixed</td></tr>\n";
echo "<tr><td>exception_date</td><td>What date</td><td>'2025-12-25'</td><td>✅ Can change</td></tr>\n";
echo "<tr><td>exception_type</td><td>Type of exception</td><td>'unavailable'</td><td>✅ Can change</td></tr>\n";
echo "<tr><td>reason</td><td>Why not available</td><td>'Christmas Holiday'</td><td>✅ Can change</td></tr>\n";
echo "</table>\n";

echo "<h4>💡 EXCEPTION EXAMPLE:</h4>\n";
echo "<div style='background: #ffebee; padding: 15px; border: 1px solid #f44336; margin: 10px 0;'>\n";
echo "<strong>Dr. Santos is sick on November 20:</strong><br>\n";
echo "• System checks: Is Nov 20 an exception day? YES<br>\n";
echo "• System response: Don't show any slots for Dr. Santos on Nov 20<br>\n";
echo "• Result: Patients cannot book with Dr. Santos that day<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<h2>5. ARE THESE TABLES NORMALIZED? LET'S CHECK!</h2>\n";

echo "<h3>🤔 NORMALIZATION ANALYSIS:</h3>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th>Table</th><th>Normalization Level</th><th>Issues?</th><th>Justification</th></tr>\n";
echo "<tr><td>appointment_referrals</td><td>2NF (mostly)</td><td>⚠️ Some denormalization</td><td>Performance - avoids JOINs for common queries</td></tr>\n";
echo "<tr><td>doctor_schedules_v2</td><td>3NF ✅</td><td>None</td><td>Properly normalized</td></tr>\n";
echo "<tr><td>doctor_schedule_slots_v2</td><td>2NF</td><td>⚠️ doctor_id repeated</td><td>Performance - faster slot lookups</td></tr>\n";
echo "<tr><td>doctor_availability_exceptions_v2</td><td>3NF ✅</td><td>None</td><td>Properly normalized</td></tr>\n";
echo "</table>\n";

echo "<h3>🔧 SIMPLIFIED ALTERNATIVE (More Normalized):</h3>\n";
echo "<div style='background: #f1f8e9; padding: 15px; border: 1px solid #8bc34a; margin: 10px 0;'>\n";
echo "<strong>If you prefer simpler, more normalized tables:</strong><br><br>\n";

echo "<strong>1. referrals_v2</strong> (just referral info)<br>\n";
echo "• id, patient_id, referred_by, reason, diagnosis<br><br>\n";

echo "<strong>2. appointments_v2</strong> (just appointment info)<br>\n";
echo "• id, referral_id, doctor_id, appointment_date, appointment_time, status<br><br>\n";

echo "<strong>3. doctor_schedules</strong> (when doctors work)<br>\n";
echo "• id, doctor_id, day_of_week, start_time, end_time<br><br>\n";

echo "<strong>Pros:</strong> Cleaner separation, easier to understand<br>\n";
echo "<strong>Cons:</strong> Need JOINs for common queries<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<h2>6. DATA LIFECYCLE: FROM CREATION TO COMPLETION</h2>\n";

echo "<h4>📋 STEP-BY-STEP DATA FLOW:</h4>\n";
echo "<ol>\n";
echo "<li><strong>Admin sets up doctor schedules</strong>\n";
echo "   <ul><li>INSERT into doctor_schedules_v2: Dr. Santos works Mon-Fri 8AM-5PM</li></ul>\n";
echo "</li>\n";
echo "<li><strong>System generates available slots</strong>\n";
echo "   <ul><li>INSERT into doctor_schedule_slots_v2: Creates all time slots for next 30 days</li></ul>\n";
echo "</li>\n";
echo "<li><strong>Nurse creates referral with appointment</strong>\n";
echo "   <ul><li>INSERT into appointment_referrals: Patient + referral info + assigned appointment slot</li>\n";
echo "   <li>UPDATE doctor_schedule_slots_v2: Mark slot as booked</li></ul>\n";
echo "</li>\n";
echo "<li><strong>Patient arrives and checks in</strong>\n";
echo "   <ul><li>UPDATE appointment_referrals: Set checked_in_at timestamp</li></ul>\n";
echo "</li>\n";
echo "<li><strong>Doctor completes consultation</strong>\n";
echo "   <ul><li>UPDATE appointment_referrals: Set appointment_completed_at timestamp</li></ul>\n";
echo "</li>\n";
echo "<li><strong>If appointment needs rescheduling</strong>\n";
echo "   <ul><li>UPDATE appointment_referrals: Change date/time, update status</li>\n";
echo "   <li>UPDATE doctor_schedule_slots_v2: Free old slot, book new slot</li></ul>\n";
echo "</li>\n";
echo "</ol>\n";

echo "<hr>";
echo "<h2>7. WHICH DATA IS FIXED VS FLEXIBLE?</h2>\n";

echo "<div style='background: #f3e5f5; padding: 15px; border: 1px solid #9c27b0; margin: 10px 0;'>\n";
echo "<strong>❌ NEVER CHANGES (Audit Trail):</strong><br>\n";
echo "• Who created the referral (created_by_employee_id)<br>\n";
echo "• When referral was created (created_at)<br>\n";
echo "• Original referral number<br>\n";
echo "• Patient being referred<br><br>\n";

echo "<strong>🔄 CAN BE UPDATED (Business Logic):</strong><br>\n";
echo "• Appointment date/time (rescheduling)<br>\n";
echo "• Assigned doctor (reassignment)<br>\n";
echo "• Referral reason/diagnosis (medical updates)<br>\n";
echo "• Appointment status (workflow progression)<br><br>\n";

echo "<strong>🤖 SYSTEM MANAGED (Automatic):</strong><br>\n";
echo "• Check-in timestamps<br>\n";
echo "• Completion timestamps<br>\n";
echo "• Slot availability flags<br>\n";
echo "• Update timestamps<br>\n";
echo "</div>\n";

echo "<hr>";
echo "<p><strong>CONCLUSION:</strong> The tables might seem complex, but they handle real healthcare workflows. The main question is: Do you prefer the integrated approach (fewer JOINs) or separated approach (more normalized)?</p>\n";

?>