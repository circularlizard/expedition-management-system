# **OIDC Authentication & Access Control Architecture**

**System Architecture & Component Responsibility Breakdown**

**Target Platform:** WordPress 7.x, Tutor LMS, custom OIDC plugin (login-with-google fork), Expedition Management System (EMS) plugin, and User Menus plugin.

## **1\. Executive Summary**

This document details the strategy for restricting WordPress site authentication exclusively to Online Scout Manager (OSM) via OpenID Connect (OIDC). Native registration (via standard WordPress and Tutor LMS forms) will be completely disabled. New users authenticating via OSM will be automatically provisioned on demand with the custom user role **Explorer**.

To maintain strict modularity, responsibilities are clearly split across components:

* **Custom OIDC Plugin (login-with-google fork):** Handles the low-level OAuth2/OIDC protocol exchange, JWT ID token validation, and issuing core WordPress authentication sessions.  
* **Expedition Management System (EMS) Plugin:** Operates as the central site governance layer. It enforces custom WordPress role assignment (`ems_explorer`, `ems_parent`, `ems_leader`) based on OSM login payload, provides an administration screen under the Settings Page, and intercepts unauthorized or under-privileged access attempts to protected routes.  
* **User Menus Plugin (user-menus):** Controls front-end menu visibility and dynamic nav elements (e.g., hiding/showing navigation links based on user status or the Explorer role).

## **2\. Component Responsibility Matrix**

| Component / Layer | Primary Responsibility | Key Hooks & Functions |
| :---- | :---- | :---- |
| **WordPress Core & Tutor LMS** | Basic site settings; native registration forms explicitly locked down. | Settings \> General, Tutor LMS \> Settings |
| **Custom OIDC Plugin** (login-with-google fork) | Low-level OIDC protocol execution, OSM authorization handshake, token decoding, user lookup/creation execution. | Provides hooks: `oauth.login_register_user`, `oauth.login_user_created`, `oauth.login_user_logged_in` |
| **Expedition Management System** (EMS Plugin) | Access control & RBAC orchestration, dynamic route/role interception, Access Control Admin UI settings tab. | Hooks into OIDC: `oauth.login_user_created`, `oauth.login_user_logged_in` WP Hooks: `template_redirect`, `admin_init` |
| **User Menus Plugin** (user-menus) | Front-end Navigation Bar UI visibility (hiding/showing links by role/login status, dynamic user tags). | Hooks into WP Menu Filters: wp\_nav\_menu\_objects |

## **3\. Implementation Workflow**

flowchart TD  
    subgraph EMS\_Protection \["EMS Route & Role Protection (template\_redirect)"\]  
        A\[User Accesses Route\] \--\> B{Is Page Protected or Tutor LMS?}  
        B \-- No \--\> C\[Render Page Normally\]  
        B \-- Yes \--\> D{Is User Logged In?}  
        D \-- No \--\> E\[Redirect to OIDC Authorization via OSM\]  
        D \-- Yes \--\> F{Does User Have Allowed Role?}  
        F \-- Yes \--\> C  
        F \-- No \--\> G\[Show Access Denied / Redirect to Home\]  
    end

    subgraph OIDC\_Plugin \["Custom OIDC Plugin (login-with-google)"\]  
        E \--\> H\[User Authenticates in OSM\]  
        H \--\> I\[OIDC Callback Handler\]  
        I \--\> J{User Exists in WP?}  
    end

    subgraph EMS\_Hooks \["EMS Integration Hooks"\]  
        J \-- No \--\> K\[OIDC Plugin 'registration_enabled' is Checked \--\> Register User\]
        K \--\> L\[Create WP User & Trigger 'oauth.login_user_created'\]
        L \--\> M\[EMS assigns custom mapped role\]
        M \--\> N\[Establish WP Session\]
          
        J \-- Yes \--\> O\[EMS processes & refreshes mapped roles\]
        O \--\> N  
    end

    N \--\> P\[Redirect User to Original Target URL\]

## **4\. Technical Configuration & Delineation**

### **Phase 1: WordPress Core & Tutor LMS Configuration (Native Lockdown)**

1. **WordPress Core Settings:**  
   * Navigate to **WP Admin \> Settings \> General**.  
   * Uncheck **"Anyone can register"** under *Membership*.  
   * *Result:* Blocks native front-end registration (/wp-login.php?action=register) and standard public signup endpoints.  
2. **Tutor LMS Settings:**  
   * Navigate to **WP Admin \> Tutor LMS \> Settings \> Advanced**.  
   * Disable **Enable Student Registration**.  
   * Clear or unassign the **Student Registration Page** dropdown.

### **Phase 2: Custom OIDC Plugin Configuration (login-with-google fork)**

The OIDC plugin is kept minimal and focused strictly on identity provider communication:

* Configured with OSM OAuth2/OIDC Credentials (Client ID, Client Secret, Scope, and Authorization/Token endpoints).  
* Processes authorization code exchange upon OAuth callback.  
* Triggers hooks (`oauth.login_register_user`, `oauth.login_user_created`, and `oauth.login_user_logged_in`) during authentication so third-party plugins (EMS) can extend behavior without editing OIDC core code.

### **Phase 3: Expedition Management System (EMS) Plugin Enhancements**

All custom access control logic, administration screens, registration overrides, and role management are encapsulated inside **EMS**.

##### **1\. Custom Administration Settings in EMS Settings Page**

EMS will expose an admin settings tab inside the existing **Settings** page for site administrators to manage protected routes and role permissions dynamically.

* **Menu Location:** **Expedition Management \> Settings \> Access Control Settings** tab (`admin.php?page=ems-settings&tab=access_control`).  
* **Interface Elements:**  
  * **Protected Pages Picker:** Multi-select checklist listing all published WP Pages.  
  * **Allowed Roles Picker:** Checklist of user roles allowed to access protected pages (defaults to `ems_explorer` and `administrator`).  
  * **Tutor LMS Route Toggle:** Toggle (Enable Tutor LMS Route Protection) to automatically guard all Tutor LMS endpoints.  
  * **Unauthorized Redirect Target:** Dropdown to select fallback page for authenticated users lacking an allowed role (or default home URL redirect).

All settings fields will be registered under the existing EMS settings infrastructure (`ems_settings_group`) and rendered in `Settings_Page::render_access_control_tab()`.

```php
add_action('admin_init', function() {  
    register_setting('ems_settings_group', 'ems_protected_pages', array(  
        'type'              => 'array',  
        'sanitize_callback' => 'ems_sanitize_protected_pages',  
        'default'           => array(),  
        'autoload'          => true, // Autoloads into memory ($0 extra DB queries)  
    ));

    register_setting('ems_settings_group', 'ems_allowed_roles', array(  
        'type'              => 'array',  
        'sanitize_callback' => 'ems_sanitize_roles',  
        'default'           => array('ems_explorer', 'administrator'),  
        'autoload'          => true,  
    ));

    register_setting('ems_settings_group', 'ems_protect_tutor_lms', array(  
        'type'              => 'boolean',  
        'sanitize_callback' => 'rest_sanitize_boolean',  
        'default'           => true,  
        'autoload'          => true,  
    ));  
});
```

#### **2\. Role Assignment in EMS**

The assignment of roles like `ems_explorer`, `ems_parent`, and `ems_leader` on successful OIDC provisioning is already dynamically implemented by EMS inside [OIDC_Login_Handler](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php#L6) hooking into `oauth.login_user_created` and `oauth.login_user_logged_in`. No additional hook is needed.

#### **4\. Route & Role Interception in EMS**

EMS hooks into template\_redirect to evaluate incoming requests against saved route protection settings AND allowed roles:

/\*\*  
 \* EMS Plugin: Intercept protected requests, enforce authentication, and check role permissions.  
 \*/  
add\_action('template\_redirect', function() {  
    // Retrieve protection configuration from autoloaded options  
    $protected\_page\_ids \= get\_option('ems\_protected\_pages', array());  
    $allowed_roles      = get_option('ems_allowed_roles', array('ems_explorer', 'administrator'));  
    $protect\_tutor      \= get\_option('ems\_protect\_tutor\_lms', true);

    // Evaluate current request  
    $is\_tutor\_page \= $protect\_tutor && function\_exists('tutor') && (is\_tutor\_dashboard() || is\_single\_course());  
    $is\_protected  \= (\!empty($protected\_page\_ids) && is\_page($protected\_page\_ids)) || $is\_tutor\_page;

    if (\!$is\_protected) {  
        return;  
    }

    // 1\. Unauthenticated Check \-\> Redirect to OIDC Login  
    if (\!is\_user\_logged\_in()) {  
        $target\_url \= esc\_url\_raw($\_SERVER\['REQUEST\_URI'\]);  
        wp\_redirect(wp\_login\_url($target\_url));  
        exit;  
    }

    // 2\. Authenticated Role Check \-\> Verify User Roles against Allowed Roles  
    $current\_user \= wp\_get\_current\_user();  
    $has\_role     \= array\_intersect($allowed\_roles, (array) $current\_user-\>roles);

    if (empty($has\_role)) {  
        // User logged in but lacks required role \-\> Access Denied  
        wp\_die(  
            esc\_html\_\_('Access Denied: Your account role does not have permission to view this resource.', 'ems'),  
            esc\_html\_\_('Access Denied', 'ems'),  
            array('response' \=\> 403\)  
        );  
    }  
});

## **5\. Plugin Integration: User Menus (user-menus)**

### **Compatibility & Conflict Assessment**

**Conclusion: No conflict.** The user-menus plugin operates on a completely different WordPress layer (front-end menu rendering) and forms a **complementary UI layer** alongside EMS's backend enforcement layer.

graph TD  
    REQ\[User Request\] \--\> FE\[FRONT-END / DISPLAY\<br\>User Menus Plugin\<br\>wp\_nav\_menu\_objects\]  
    REQ \--\> BE\[BACKEND / SECURITY\<br\>EMS Plugin\<br\>template\_redirect\]

    subgraph FrontEnd \["Front-End Layer"\]  
        FE \--\> FE1\[Filters Navigation Menu Items\]  
        FE \--\> FE2\[Hides links unauthenticated users shouldn't see\]  
        FE \--\> FE3\[Displays dynamic tags e.g., Logged in as User\]  
    end

    subgraph BackEnd \["Backend Security Layer"\]  
        BE \--\> BE1\[Enforces Page/Route Security\]  
        BE \--\> BE2\[Intercepts direct URL access\]  
        BE \--\> BE3\[Blocks non-allowed roles with HTTP 403\]  
    end

### **Architectural Synergy Breakdown**

1. **Defense-in-Depth Model:**  
   * **user-menus (Presentation Layer):** Hides menu items dynamically from users who aren't logged in or lack the `ems_explorer` role. This cleans up the UI so users don't see links to pages they cannot view.  
   * **EMS Plugin (Enforcement Layer):** Ensures that if a user directly types or bookmarks a URL (bypassing the menu), the request is intercepted at template_redirect and blocked/redirected.  
2. **Native Role Recognition:**  
   * Since EMS registers and assigns the custom WordPress role **ems_explorer**, user-menus automatically detects `ems_explorer` in the **Appearance > Menus** item settings (*Who can see this menu item? > Logged In Users > Choose Roles > ems_explorer*).  
3. **Login / Logout Dynamic Links:**  
   * user-menus provides native {login} and {logout} menu item controls:  
     * **Logout Item:** Calls wp\_logout\_url(), cleanly invalidating the WordPress auth session created by the OIDC plugin.  
     * **Login Item:** Calls wp\_login\_url(). Because the OIDC plugin intercepts standard WP login requests, clicking the Login item seamlessly initiates the OSM OIDC OAuth handshake.

## **6\. Performance & Security Analysis**

| Metric | Analysis |
| :---- | :---- |
| **Execution Overhead** | **![][image1]** per request. Interception logic relies on lightweight in-memory checks (is\_user\_logged\_in(), $user-\>roles intersection). |
| **Database Overhead** | **![][image2]**. Admin options (ems\_protected\_pages, ems\_allowed\_roles) use autoload \= true and load in WP's initial boot query. |
| **Attack Surface Reduction** | Complete mitigation of automated spam account registration across native WordPress and Tutor LMS forms. |
| **Access Governance** | Granular Role-Based Access Control (RBAC) ensures non-permitted logged-in users cannot bypass route guards. |
| **Session Integrity** | Sessions are strictly authenticated against server-side OIDC claims issued by Online Scout Manager. |
| **UI Cleanliness** | user-menus removes unauthorized navigation links, preventing end-user confusion and unauthorized access attempts. |

## **7\. Verification & Testing Protocol**

| Test Case | Component Tested | Procedure | Expected Result |
| :---- | :---- | :---- | :---- |
| **Direct Registration Block** | WP Core / Tutor LMS | Visit /wp-login.php?action=register directly in browser. | Redirected or shown error: *"Registration is disabled"*. |
| **Public Page View** | EMS Interceptor | Visit non-protected pages while logged out. | Page renders normally; protected menu items remain visible or hidden per user-menus rules. |
| **Menu Item Visibility (Logged Out)** | User Menus Plugin | Access site logged out; inspect navigation bar. | Menu items set to "Logged In Users" or "ems_explorer" are hidden from view. |
| **EMS Admin Protection UI** | EMS Plugin | Select a page & required role in settings under the **Access Control** tab. | Settings save and update autoloaded WP options. |
| **Protected Menu Click (Logged Out)** | EMS Interceptor | Click protected page (e.g., /dashboard/) while logged out. | Automatically redirected to OSM OIDC login screen. |
| **OIDC Provisioning** | OIDC Plugin \+ EMS Hooks | Authenticate with a new OSM user account for the first time. | Account created automatically, role mapped/set to `ems_explorer` / `ems_parent` / `ems_leader`, redirected back to target URL. |
| **Menu Item Visibility (Logged In Explorer)** | User Menus Plugin | Log in as Explorer; inspect navigation bar. | Menu items configured for `ems_explorer` and {logout} display properly with dynamic tags (e.g., username/first name). |
| **Role Protection Check (Insufficient Role)** | EMS Interceptor | Log in as a user with a non-allowed role (e.g., Subscriber) and access a protected page directly via URL. | Blocked with HTTP 403 "Access Denied" response. |
| **Authorized Access (Allowed Role)** | EMS Interceptor | Log in as `ems_explorer` or Administrator and access protected page. | Page renders successfully without redirection. |

[image1]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEUAAAAWCAYAAACWl1FwAAAAtUlEQVR4Xu3UPQpCMRAE4OdPoSA2EpuEJJAU3sVzeCfxFlp7AhHsbB8WFjaChZUWzutkwF6Y/WCLZLoh2aYxxhhj/l5Kacl3slDGBvPKOS84k4Mi9phHKWXOmZohijhjLiGEMYdSaq1TFHHDHHHscy4Fr8GjiCf2xY4zWd3yRCnvGOOaM3lfL2bLmTzn3ATlXFHOAcce5+oGKOeEaVHQiEN53ZdCOXfv/YwzeVjGK74zxhjz2wcLgh2l2y0tuwAAAABJRU5ErkJggg==>

[image2]: <data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAHIAAAAWCAYAAAAcuMgxAAAE/0lEQVR4Xu1YaWhcVRSeVNwVqxKDmWTeTBKJjVs1P2xF1FZF8J9FS3HBomAFcStKsUqpS1SodakbiOLSYilasVq3UvSHP1wq/pHaKojibigU3LB2id837zvT45k3mY4xP0LeByfvnO+ce+695757c98UCjly5MiRI0eOUdHR0XFouVzekCTJCGQTqLYYk2N8gbpvg3we+X1GV1dXkQuI58G0i8Xi0bShTgmh4wr0uSVykwnaRKz7fwMa/4HduCZwn0D+8tx4oqen54jJvpBYg4MKY9k8fAtKpdLcwC0e09vRIvTiTOqFHBMqlcpZWsgzPY+340ryOG6P8nzEwMDAAYh7BrIZbZYa39nZeQhzg5sBOR3+2eShnwP9VDyn4zmLHJ63sy/Id5ALKZYH+qWIvRPP1bJvhT3f/OKug2yEbME8FnlfM3B+yPcw2i4zDvobUtugXw3//XiuNT/6uByyBNxK4wzKtw6+TYi50fju7u5e2NeDfwxyBmQa7Cfb29sPox9t5innqr3ZUjTKaajWHX9uStIinuadaHAJeS6C5z3gn8UYxB4p+yXa1NFuapIu0A7leV4xX6i/7ZAhDLIPz4XifpG+0PVhi0zZjZfjZOlXyf81bRfPnb1PJwniflAuvlBT8PwGBT/R2vMl1SIzppZTBf8XRySaR0EXRbT9EvYO6TMS1Qf6Gjzn+BxcjFZzyj/S399/OJW7aLBAtdZpwEVKfJnnPeS/J3KY6A2RwwA+lv4eZKb3W0zS4GgFv5x+jLGDNnJtKGhi4NfSF+I5hns9F4GYp9TnsYEnV5cvg1sZOL4I7Pdcx1XbYtyneNvacWdDv8V8sJ9tNWctHkHX0EBxpvtg8BdnJTHYjsWOOokFNtFA3/exyH28ct1X1s6MULtGC7ksTLAheDqor1ejz0P91eVEu48inxUL+wXPQX+ctq+Fq8fdLo72K2Z7WI5oj5ZT+kjtf2QSdgkmdAV5fpp43gDfKvqxMBcg9jwvSTimCfDPaZCZtzKNYWvkiaTJQnKHaizr0M9c02Och/rbFfkxLOT3tGMtVI+Ki2Ou5WZ7gF/Ras7e3t5jqm36+voOVHBLt1YUagH9/B8XfVnQBHZBdkYfIf9XZmM8S5yv4UKq3Z7IYXyveS5C7epytrCQL3oO+uoYkwXGlBoc+xjzI63m5PrVDA10hfOTe7NZErV7IoNfH+xPIdMKOvMhL3u/YjjBb539oNMfyBoL3sZu5atefAziXkdh5iNnj/cZ4B/OyllOLxNx0Zgvcr96jjdL2nFDAG3IeZsZyjXk/DWAf7TVnPBvr7FJxu5Th3M8F4EOFjEOScvGwX4X5lTqg4OD+8P+0Ocu65bM48E4InGFQcy1IWe8WNSgXG+bXdKFAfIZUiy1630WFPeB2eX0Zkku1uItz+mXsJ3k/I6AvT6DGzZdNvM/7TlDkrEDm+WkD+M5zmxLstuSseA15yhA4QYTfWJQYJ8gnpehn5P0nB8uFotd5KH/BvlR/DaXit9sfyrHO0bC/l3x/Mb8KQm/NunzhUf2iNoOcBfKrjstAvZL0jFa2/PVV91LA26zi3sIzyGzIXdYHOo2z/F/8+dO8qX0k4WfV5x3de4V96WQ7J1ntV787myWU+02lppc7CYlVMy6hcwxwZCkuz5fyIkMHGE3YxH36AhbzG+2GJNjAgCLNxsyk5ceyNnNfmfOkeN/xz8VLiSC5qKPkAAAAABJRU5ErkJggg==>