# **SEEE Website Handover Guide**

Welcome to the SE Explorer Expeditions (SEEE) website setup. This guide explains how our public site is laid out, why we built it this way, and how the registration forms and custom code work behind the scenes.

## **How the site menus work (and why)**

The site header is split into two distinct areas: the **Utility Bar** at the very top, and the **Main Navigation Bar** below it.

### **Utility Top Bar**

Sitting right at the top of the screen, the Utility Bar handles quick, everyday actions without cluttering our main menu:

* **Event Calendar:** (/events/category/seee/) Quick link to upcoming expedition dates and iCal feeds.  
* **FAQ & Support:** (/faq/) Easy path to common questions and contact details.  
* **Login / Logout:** Shows whether a user is logged in or out.

### **Main Navigation & Dropdown Menus**

All primary pages are grouped into four clear dropdown menus on the main bar:

* **\[SEEE Logo\]**  / (Homepage)  
* **1\. Sign Up** (/signup)  
  * Overview (/signup/)  
  * Sign up for a DofE award (/signup\#new)  
  * Sign up for an expedition (/signup\#expedition)  
  * Transfer your Dofe to Scouts (/signup\#transfer)  
* **2\. Training** (/training)  
  * Overview (/training/)  
  * My Learning (/dashboard)  
  * Bronze Preparation (/courses-for-bronze/)  
  * Silver Preparation (/courses-for-silver/)  
  * Gold Preparation (/courses-for-gold/)  
  * First Aid for Expeditions (/first-aid/)  
  * Online Course Help (/online-course-help/)  
* **3\. Parents & Leaders** (/parents)  
  * Overview for Parents/Carers (/parents/)  
  * Overview for Leaders (/leaders/)  
  * Volunteering With Us (/volunteering/)  
  * Costs (/costs/)  
* **4\. About SEEE** (/about-seee/)  
  * FAQ (/faq/)  
  * News (/news/)

### **Why we built it this way**

* **Keeping top-level menus focused:** Putting everyday utility links (calendar, FAQs, login) in the top bar leaves the main menu free for our four primary user journeys (Sign Up, Training, Parents & Leaders, About).  
* **One main starting point (/signup):** Rather than sending parents and Scouts to different registration forms right away, all signup links route through /signup. This makes sure everyone sees important context like age requirements before starting a form.  
* **Jumping straight to the right section:** Sub-items in the "Sign Up" dropdown use anchor links (/signup\#new, /signup\#expedition, /signup\#transfer) so visitors land on the Signup page with the right accordion section already open.  
* **Clear choices on the homepage:** The homepage hero uses 4 simple scenario choices (*Start a new level*, *Do an expedition*, *Transfer*, or *Volunteer*) instead of generic "click here" buttons.

## **How the signup form pages work**

When someone clicks a signup button inside an accordion section on /signup, they are taken to a dedicated form page. These 3 form pages are intentionally **kept off the main navigation menus** so visitors don't skip the essential information on the main Signup page.

### **The 3 Registration Form Pages**

| Pathway | Target Form Page URL | How it works |
| :---- | :---- | :---- |
| **1\. Award Sign-up** | /signup-dofe-award/ | **Fluent Forms:** Collects signup, target award level (Bronze/Silver/Gold) and payment |
| **2\. Expedition Sign-up** | /signup-expedition/ | **Fluent Forms:** Captures available weekend dates, team preferences. |
| **3\. DofE Transfer Request** | /signup-transfer/ | **Fluent Forms:** Gathers 7-digit eDofE ID numbers and previous school/centre info to transfer records. |

*Note: The **Volunteer** pathway doesn't use an automated form page. It directs interested adults to view role descriptions on /volunteer/ and then email our team directly at expeditions@sesscouts.org.uk.*

### **Managing Forms in WordPress**

* All three forms are built using the **Fluent Forms** plugin (**Fluent Forms** → **Forms** in the WordPress Dashboard).  
* Form fields, email notifications, and submission entries are configured inside Fluent Forms.  
* Form pages embed these forms using the Fluent Forms Elementor Widget.  
* The Expedition Management System protects the pages mentioned above and forces the user to login before accessing them

## **How deep-linking to signup works**

When someone clicks a link like /signup\#expedition or /signup\#transfer, the browser opens the /signup page.

Standard browsers try to jump to an HTML id, but they don't automatically open closed accordion tabs or account for sticky site headers covering the top of the text.

To fix this, a small custom JavaScript snippet runs on the /signup page to handle incoming \# links cleanly.

### **What the script does:**

1. **Reads the URL:** Checks window.location.hash when the page loads or when a hash changes.  
2. **Finds the right tab:** Maps anchor keywords (\#new, \#expedition, \#transfer, \#volunteer) to their matching accordion item.  
3. **Opens the accordion:** If the section is closed, it triggers a native .click() event on the tab title bar (\<summary\>) to open it with Elementor's standard animation.  
4. **Smoothly scrolls into view:** Waits briefly for the opening animation, calculates the tab's vertical position, subtracts 120px so the sticky top header doesn't cover it, and scrolls the user gently into place.

## **Making changes to the signup forms**

* **Editing Accordion Text:** Edit the /signup page in Elementor. Click on the **Nested Accordion** widget (e-n-accordion) to update text or button links inside each pathway.  
* **Editing Registration Forms:** Go to **Fluent Forms** ![][image1] **Forms** in the WP Admin sidebar to update form questions, notification emails, or confirmation messages.  
* **Changing Accordion Order:** If you rearrange the accordions on /signup, update the ACCORDION\_MAPPING numbers inside the script to match the new item sequence (1, 2, 3, 4).  
* **Testing Links:** Open your browser console (Developer Tools) and visit:  
  * see-expeditions.org.uk/signup\#new  
  * see-expeditions.org.uk/signup\#expedition  
  * see-expeditions.org.uk/signup\#transfer  
  * see-expeditions.org.uk/signup\#volunteer  
    Look for \[SEEE Deep-Link Handler\] messages in the console log to confirm execution.

## **Dynamic Signup Banner & Email OTP Verification**

To simplify registrations, parents can optionally log in via Online Scout Manager (OSM) to auto-fill their child's details. Guests (logged-out) can type details manually but must verify their email ownership via a One-Time Passcode (OTP) sent to their inbox.

### **1. Setting up the Signup Banner Shortcode**

On each form registration page (e.g. `/signup-dofe-award/` or `/signup-expedition/`), add the custom `[ems_signup_banner]` shortcode immediately above your Fluent Form block or Elementor widget:

```text
[ems_signup_banner form_id="6" type="participant" scout_field="signup_child" unit_field="signup_unit" parent_email_field="signup_parent_email" parent_otp_field="signup_parent_otp_code" explorer_otp_field="signup_explorer_otp_code" headline="Speed up your DofE registration" message="Log in with Online Scout Manager to auto-fill your child's details and skip email confirmation."]
```

#### **Shortcode Parameters:**
* **`form_id`**: The Fluent Form ID (default: `6`).
* **`type`**: The signup type (`participant` or `expedition`).
* **`scout_field`**: Field name of the child select dropdown (default: `signup_child`).
* **`unit_field`**: Field name of the ESU patrol/unit dropdown (default: `signup_unit`).
* **`parent_email_field`**: Field name of the parent email address input (default: `signup_parent_email`).
* **`parent_otp_field`**: Field name of the parent verification code input (default: `signup_parent_otp_code`).
* **`explorer_otp_field`**: Field name of the explorer verification code input (default: `signup_explorer_otp_code`).
* **`headline`** *(Optional)*: Custom headline text for the OIDC login banner.
* **`message`** *(Optional)*: Custom message body for the OIDC login banner.

---

### **2. Configuring the Fluent Form Editor**

To enable OTP verification, open the target form in the **Fluent Forms editor** and add the following fields:

#### **A. Parent Email Verification Elements**
1. **Email Field:** Add an Email Address element with the Name Attribute set to `signup_parent_email` and mark it **Required: Yes**.
2. **"Send Code" Trigger Button:** Add a **Custom HTML** element directly below the Parent Email input and paste the following markup:
   ```html
   <div class="ems-otp-wrap" data-target="signup_parent_email" style="margin-bottom: 15px;">
       <button type="button" class="btn-send-fluent-otp ff-btn ff-btn-secondary" style="margin-top: 5px;">
           Send Verification Code
       </button>
       <span class="fluent-otp-status" style="margin-left: 10px; font-size: 0.9em;"></span>
   </div>
   ```
3. **OTP Code Field:** Add a **Numeric** or **Simple Text** element with the Name Attribute set to `signup_parent_otp_code`, element label set to `Parent Email Verification Code`, and mark it **Required: Yes**.

#### **B. Explorer Email Verification Elements (If needed)**
1. **Email Field:** Add an Email Address element with the Name Attribute set to `signup_explorer_email` and mark it **Required: Yes**.
2. **"Send Code" Trigger Button:** Add a **Custom HTML** element directly below the Explorer Email input and paste the following markup:
   ```html
   <div class="ems-otp-wrap" data-target="signup_explorer_email" style="margin-bottom: 15px;">
       <button type="button" class="btn-send-fluent-otp ff-btn ff-btn-secondary" style="margin-top: 5px;">
           Send Verification Code
       </button>
       <span class="fluent-otp-status" style="margin-left: 10px; font-size: 0.9em;"></span>
   </div>
   ```
3. **OTP Code Field:** Add a **Numeric** or **Simple Text** element with the Name Attribute set to `signup_explorer_otp_code`, element label set to `Explorer Email Verification Code`, and mark it **Required: Yes**.

---

### **3. Dynamic Visibility & Validation Rules (How it behaves)**

You do not need to configure complex conditional logic inside Fluent Forms. The EMS plugin handles the states automatically:

* **When Parent is Logged In (OIDC Verified):**
  * The parent's email is already verified.
  * The plugin dynamically injects CSS styles to hide the Parent Verification Code input (`signup_parent_otp_code`) and the Parent "Send Code" HTML trigger button.
  * The plugin strips the `required` validation constraints on both the frontend and backend for the Parent OTP field.
  * **Note:** The Explorer Email Verification Code remains visible and required (if mapped) because explorer emails are not synchronized from OIDC.
* **Email Deduplication (Same Email for Parent & Explorer):**
  * If a guest types the exact same email address in both the parent email and explorer email fields:
    * The frontend JavaScript hides the explorer OTP input block dynamically.
    * The backend validation bypasses the explorer OTP validation check (meaning the user only receives and verifies a single passcode for that inbox).

---

## **Logged-in portal and backend systems (Coming soon)**

*Documentation for the logged-in user portal and Expedition Management System (EMS) will be added here in a future update.*

### **Planned scope:**

* **Participant & Parent Portal:** User dashboard (/my-expeditions), active booking tracking, payment balances, and required training module links.  
* **Adult Volunteer Hub:** Shift signups, assigned roles (supervisor, assessor, checkpoint crew), and operational document downloads (route cards, risk assessments, emergency procedures).  
* **EMS Integration & Forms Processing:** Automatic form processing, eDofE ID verification, and syncing registration data with the Expedition Management System.  
* **Safety & Compliance Tracking:** Automatic tracking of adult volunteer PVG/DBS clearance, Scout membership status, and First Aid certificate expiry dates.

## **Reference: Scouts Tone of Voice Guide**

When using AI to generate text for the site, the Scouts tone of voice guide is very helpful to ensure the content generated is friendly and readable \- [https://cms.scouts.org.uk/media/13411/how-we-talk.pdf](https://cms.scouts.org.uk/media/13411/how-we-talk.pdf) 

## **Appendix: The code running on the Signup page**

The script is installed inside an **Elementor HTML Widget** at the bottom of the /signup page template:

(function () {  
  'use strict';

  // 1\. Mapping custom URL hash anchors to accordion item positions  
  const ACCORDION\_MAPPING \= {  
    '\#new': 1,        // 1st item: New to DofE / Next Level  
    '\#award': 1,      // Alias for 1st item  
    '\#expedition': 2, // 2nd item: Ready to Book An Expedition  
    '\#transfer': 3,   // 3rd item: Transferring from School/Elsewhere  
    '\#volunteer': 4,  // 4th item: Want to Volunteer  
    '\#volunteers': 4  // Alias for 4th item  
  };

  // Pixel offset to stop sticky header from covering the expanded title  
  const STICKY\_HEADER\_OFFSET \= 120;

  function expandTargetAccordion() {  
    let rawHash \= window.location.hash ? window.location.hash.toLowerCase() : '';  
    console.log('\[SEEE Deep-Link Handler\] Evaluating URL hash:', rawHash || '(none)');  
    if (\!rawHash) return;

    // Standardize aliases  
    if (rawHash \=== '\#award') rawHash \= '\#new';  
    if (rawHash \=== '\#volunteers') rawHash \= '\#volunteer';

    let targetTabTitle \= null;

    // Method 1: Direct ID Match on Elementor settings ID or \<details\> tag  
    try {  
      const directElement \= document.querySelector(rawHash);  
      if (directElement) {  
        if (directElement.tagName \=== 'SUMMARY' || directElement.classList.contains('e-n-accordion-item-title') || directElement.classList.contains('elementor-tab-title')) {  
          targetTabTitle \= directElement;  
        } else if (directElement.tagName \=== 'DETAILS' || directElement.classList.contains('e-n-accordion-item')) {  
          targetTabTitle \= directElement.querySelector('summary, .e-n-accordion-item-title, .elementor-tab-title') || directElement;  
        }  
      }  
    } catch (e) {  
      console.warn('\[SEEE Deep-Link Handler\] Invalid hash selector:', rawHash);  
    }

    // Method 2: Fallback to Index Position  
    if (\!targetTabTitle && ACCORDION\_MAPPING\[rawHash\]) {  
      const itemIndex \= ACCORDION\_MAPPING\[rawHash\];  
      targetTabTitle \= document.querySelector(  
        \`.e-n-accordion \> .e-n-accordion-item:nth-child(${itemIndex}) summary, .elementor-widget-accordion .elementor-accordion-item:nth-child(${itemIndex}) .elementor-tab-title\`  
      );  
    }

    if (targetTabTitle) {  
      console.log('\[SEEE Deep-Link Handler\] Matched target tab:', targetTabTitle);

      const detailsParent \= targetTabTitle.closest('details');  
      const isAlreadyOpen \= detailsParent   
        ? detailsParent.hasAttribute('open')   
        : targetTabTitle.classList.contains('elementor-active');

      if (\!isAlreadyOpen) {  
        console.log('\[SEEE Deep-Link Handler\] Expanding target accordion tab via simulated click...');  
        targetTabTitle.click();  
      }

      // Smooth scroll after accordion transition finishes  
      setTimeout(() \=\> {  
        const targetY \= targetTabTitle.getBoundingClientRect().top \+ window.pageYOffset \- STICKY\_HEADER\_OFFSET;  
        console.log('\[SEEE Deep-Link Handler\] Scrolling smoothly to position Y:', targetY);

        window.scrollTo({  
          top: Math.max(0, targetY),  
          behavior: 'smooth'  
        });  
      }, 300);  
    }  
  }

  // Run on page load after Elementor JS initializes  
  window.addEventListener('DOMContentLoaded', () \=\> {  
    setTimeout(expandTargetAccordion, 300);  
  });

  // Run when hash changes on-page  
  window.addEventListener('hashchange', expandTargetAccordion, false);  
})();  


[image1]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABUAAAAYCAYAAAAVibZIAAAAhklEQVR4XmNgGAWjYOCBgoJCIboYxQBo6EIZGRlVdHGKgJycnLW8vPw2dHGKAdDQbKDhaejicAD0ipCsrKwUqRho8FIgXgtio5tJFgA6RAVo4F5QUKDLkQWAEcUBNPCKtLS0DLoc2QBoYAoQF6OLUwSABu4HUizo4hQBoKGS6GKjYBTQEAAAf3kVcAxJry8AAAAASUVORK5CYII=>