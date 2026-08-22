# Privacy Policy - Expedition Management System (EMS)

**South East Scotland Scouts**

*Last Updated: August 2026*

---

## Introduction

South East Scotland Scouts collects and processes personal data in order to carry out its business. As a membership organisation we hold data on members and also staff and others, such as customers and donors. 

We take the issue of privacy very seriously and are committed to protecting and respecting your privacy in compliance with data protection law. This includes when you use our online services and this privacy statement should also be read alongside our [website terms and conditions](https://sesscouts.org.uk/terms/).

This specific Privacy Policy explains how we collect, store, use, and protect your personal information when you use the Expedition Management System (EMS) portal, submit signup forms, upload route cards/GPX files, or manage volunteer availability. 

---

## 1. Who We Are

We are the South East Scotland Region of the Scout Association (known as **South East Scotland Scouts**), a registered Scottish charity (SC030556). 

If you want to contact us to raise any questions about this privacy statement, or any general matters relating to the EMS or our website, you can contact us using this email address: [communications@sesscouts.org.uk](mailto:communications@sesscouts.org.uk).

* **Regional Address:** Communications Team, South East Scotland Scouts, 71 Bonaly Road, Edinburgh, EH13 0PB
* **DofE/Expedition Team Contacts:** [expeditions@sesscouts.org.uk](mailto:expeditions@sesscouts.org.uk)

---

## 2. Information We Collect

In addition to general membership information, the EMS collects and processes specific data necessary to plan, organize, and safely run expeditions and the Duke of Edinburgh’s Award program.

### A. Participant / Explorer Registration & Signups
When registering or signing up for a DofE place or expedition, we collect:
* **Explorer/Participant Details:** Full name, date of birth, email address, Scout ID (identity anchor from Online Scout Manager), patrol, unit, and section.
* **Parent/Carer Details:** Full name, email address, telephone number, and link to the explorer's record.
* **Award Level:** DofE award level (Bronze, Silver, Gold).
* **Expedition Preferences:** Options selected for specific training, practice, or qualifying expeditions.
* **First Aid Status:** Detail of any first aid qualifications held by the explorer.
* **Payment Status:** Record of paid deposits or event fees (payment processing details are handled securely by our payment gateway; we do not store credit or debit card numbers).

### B. Special Category (Sensitive) Data
To ensure the physical safety and welfare of explorers on expeditions, we collect and process:
* **Medical Information:** Details of physical or mental health conditions, allergies, regular medications, or treatments.
* **Dietary Requirements:** Special dietary needs for catering or group food planning.
* **Emergency Contact Details:** Contact name, relationship, and 24-hour phone number for at least one parent/carer or guardian during the physical expedition.

### C. Expedition Teams & Route Planning
To facilitate team organization and navigation safety:
* **Team Assignments:** Participant linkages to specific teams and event codes.
* **Route Submissions:** GPX files, map coordinates, route plans, and digital route cards uploaded by teams or leaders.
* **Assessor Feedback:** Reviews and approvals of team route submissions.

### D. Volunteer & Leader Information
To manage staff coverage and safeguarding requirements:
* **Availability:** Dates, roles, and overnight availability submitted by volunteer leaders.
* **Confirmation Status:** Internal approvals and verification of volunteer qualifications (e.g., permits, safeguarding checks, first aid).

### E. Automated & Synced Data
* **Online Scout Manager (OSM) Integration:** The system syncs with OSM to keep records up to date. We sync explorer names, parent contacts, section events, and event attendance using secure API calls. **OSM OAuth authentication tokens are processed in real-time and are never stored on our servers.**
* **Technical Logs:** We capture IP addresses, login timestamps, and session identifiers to verify access control, prevent unauthorized entry, and secure data transmission.

---

## 3. Lawful Basis for Processing

We process your data under the following lawful bases under UK GDPR and Data Protection legislation:

1. **Performance of a Contract / Service:** To deliver the expedition training, assessment, and DofE award coordination that participants sign up to receive.
2. **Legitimate Interests:** To run a safe, structured, and compliant Scouting program, coordinate regional volunteers, and optimize our website portal.
3. **Vital Interests:** Processing medical information and emergency contacts to protect the life or physical safety of participants during wilderness expeditions.
4. **Consent:** Where special category data (such as medical/dietary requirements) is voluntarily provided to us on signup forms for safety purposes.

---

## 4. How We Use Your Information

We use the information collected via the EMS to:
* Verify user roles (e.g., ensuring only verified parents, carers, or network members can register candidates).
* Organise participants into compliant expedition teams (4–7 members).
* Map and assess navigation route submissions (GPX and route cards) for outdoor safety compliance.
* Share dietary/medical summaries with event caterers and field leaders in charge of participant safety.
* Sync records with Online Scout Manager to avoid duplicate admin entry.
* Manage volunteer shifts, licensing, and supervisor credentials.

---

## 5. Sharing of Your Data

We restrict access to personal data to those who specifically require it to run or oversee expeditions:
* **Scout Leaders & Supervisors:** Field leaders in charge of your child's expedition team have access to medical forms and emergency contacts during the event.
* **Assessors & Approvers:** Appointed DofE Assessors will review team names, participant lists, and route submissions.
* **Scouts Scotland / The Scout Association:** We may share details when necessary for insurance, permit verification, or safeguarding requirements.
* **The Duke of Edinburgh’s Award (DofE):** Participant info may be synchronized to register achievements or check licensing requirements.
* **Legal Disclosures:** We will only share details with law enforcement or emergency medical services if legally required, or if a participant's vital safety is at risk.

**We do not share your details with commercial third parties, nor do we store credit card details or sell your data for advertising purposes.**

---

## 6. Data Security and Caching

We take security seriously and utilize technical safeguards to prevent unauthorized access:
* **Role Restricting:** Only users verified with the role of `ems_parent` or `ems_network_member` (alongside Site Administrators) can access signup forms. Custom role-guards actively intercept requests to block unauthorized access.
* **Client-Side Cache Scope:** Form inputs, email verification tokens, and OTP checks are stored inside the browser's `sessionStorage` and scoped strictly by form ID to prevent cross-form leakage. 
* **Auto-Clear Protocols:** Session storage caches are automatically wiped clean upon successful form submission or user logout.
* **Encryption:** Sensitive configuration parameters, client secrets, and data transfers are protected with AES-256-CBC encryption and secure HTTPS protocols.

---

## 7. Data Retention

Due to our safeguarding responsibilities and licensing rules as an Operating Authority:
* **Indefinite Retention:** We are legally required to retain certain incident records, safeguarding files, and participant attendance lists indefinitely.
* **Active Expedition Data:** Route submissions, GPX files, medical details, and volunteer availability lists are deleted or anonymized once they are no longer required to verify award completion or safety logs (typically within 1 to 3 years following the expedition).

---

## 8. Your Rights

Under current UK Data Protection legislation, you have the right to:
* Request access to the personal data we hold about you (a Subject Access Request).
* Request the correction of inaccurate or incomplete data.
* Request the deletion of data (subject to our legal and safeguarding retention requirements).
* Object to or restrict the processing of your data.

To make a Subject Access Request, please contact the Scout Association legal services team at [legal.services@scouts.org.uk](mailto:legal.services@scouts.org.uk). For regional updates or corrections to your EMS records, please contact [expeditions@sesscouts.org.uk](mailto:expeditions@sesscouts.org.uk).

If you are unsatisfied with how we handle your data, you can make a complaint to the [Information Commissioner’s Office (ICO)](https://ico.org.uk/concerns/).

---

## 9. Changes to This Policy

We may update this Privacy Policy from time to time to reflect changes in our services, system updates, or legislative changes. The "Last Updated" date at the top of the policy will indicate when the last changes were made.
