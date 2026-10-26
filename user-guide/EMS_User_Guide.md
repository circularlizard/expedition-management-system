# Expedition Management System (EMS) — User Guide

Welcome to the **Expedition Management System (EMS)**. This guide provides a high-level overview of the system, its main interface screens, and how the workflows connect to manage Duke of Edinburgh (DofE) expeditions in South East Scotland.

---

## 1. Overview & Purpose

The EMS is a WordPress-integrated platform designed to simplify and coordinate DofE expeditions. It serves as the bridge between:
- **Online Scout Manager (OSM)**: The central database (source of truth) for explorer records, sections, events, and parental details.
- **Fluent Forms**: The front-end signup mechanism where parents sign young people up.
- **Tutor LMS**: The training portal where explorers complete required online training.
- **EMS Admin Board & Participant/Parent Portal**: The dashboard where expedition leaders build teams, verify safety/first aid coverage, approve routes, and coordinate volunteers.

Rather than requiring separate usernames and passwords, users log in securely using their existing Online Scout Manager (OSM) account details.

---

## 2. User Roles & Permissions

The EMS distinguishes between four main user roles, dynamically mapped during login via Online Scout Manager (OSM) or assigned in WordPress:

### A. Parents (`ems_parent`)
- **Access Source**: Dynamically assigned when a user logs in with parental relationships linked in OSM.
- **Key Capabilities**:
  - Select and view records, teams, and expedition details for any of their linked children.
  - Initiate new signups for DofE levels (requires the Explorer to have a valid email on file in OSM).
  - View route plans, deadlines, and feedback left by the Leader-in-Charge (LiC).
  - *Important Note*: Parents can log in and view details, but any training courses completed under a parent login will not count toward the explorer's requirements. Training must be completed directly under the Explorer's own account to be recorded.

### B. Explorers / Participants (`ems_explorer`)
- **Access Source**: Dynamically assigned when a young person logs in directly using their OSM member details.
- **Key Capabilities**:
  - Access required online training courses in Tutor LMS to earn credit for completed modules.
  - View personal expedition status, team codes, and safety alerts.
  - Submit route planning materials, including uploading GPX files and Route Card PDFs.
  - View status updates and feedback from the Leader-in-Charge.

### C. Leaders & Leaders-in-Charge (`ems_leader`)
- **Access Source**: Assigned to local Explorer Scout Unit (ESU) leaders via administrator invitation from the Unit Mapping table, or mapped dynamically on login for users administering sections in OSM.
- **Key Capabilities**:
  - Access the dedicated **Front-End Unit Leader Dashboard** (`[ems-leader-dashboard]`) to monitor their unit's participants.
  - View real-time explorer registration progress, DofE levels (Bronze/Silver/Gold), expedition preferences, and OSM sync statuses without exposing sensitive personal notes or medical data.
  - Review, approve, or request changes on team route planning submissions.
  - Manage volunteer availability, confirm event staffing levels, and view staff calendars.
  - View team compositions, explorer lists, and safety details (such as First Aid coverage) for their assigned expeditions.

### D. Expedition Administrators (`administrator`)
- **Access Source**: Standard WordPress Administrator role.
- **Key Capabilities**:
  - Complete control over events (expeditions) and configuration options.
  - Execute sync actions to import reference data, section members, and attendance from OSM.
  - Reconcile Fluent Forms signup submissions with OSM records using the Signups Board.
  - Manage mappings between EMS fields and OSM Flexi-records.

---

## 3. System Screens & Features

The system is split into **Admin Dashboards** (used by Expedition Admins and Leaders-in-Charge in the WordPress background) and the **Public Portal** (used by explorers and their parents on the front-facing website).

### Admin Screens (WordPress Dashboard)

#### A. Signups Board
- **Purpose**: Reconciles public Fluent Forms submissions with OSM explorer profiles.
- **How it works**: When a parent submits a signup form, the system automatically tries to match the explorer using their unique ID or email. If there is a mismatch or a new participant, administrators can manually reconcile the record to link the form data to the correct explorer profile.

#### B. Expedition Board (Planner)
- **Purpose**: The main planning dashboard to build **Teams** and assign participants to events.
- **Key Features**:
  - **Events Dashboard**: View training, practice, and qualifying expeditions. Each event displays details like the Leader-in-Charge (LiC), locations, dates, and the linked OSM Event.
  - **Team Builder**: A modern interface to group participants into teams. It displays team sizes (ideally 4–7 members) and raises alerts if a team is too small or too large.
  - **Unassigned Pool**: View all synced participants who are not yet assigned to a team, making it easy to drag/drop them into place.
  - **First Aid Tracking**: Instantly displays the first aid qualifications of each team member to ensure safety compliance.

#### C. Volunteer Management Board
- **Purpose**: View and manage adult volunteer availability for expeditions.
- **Key Features**:
  - **Availability Breakdown**: Volunteers specify their availability (either the whole expedition or specific days/overnights).
  - **Approval Pipeline**: Admins or Leaders-in-Charge review pending signups and mark volunteers as *Confirmed* or *Pending*.
  - **Multi-View Calendars**: View volunteer coverage in a monthly calendar, look at specific expedition staff lists, or view an individual volunteer's overall commitments.

#### D. Unit Lookup & Leader Invitations
- **Purpose**: Map Explorer Scout Units (ESUs) to OSM sections/patrols, configure unit leader contact emails, and manage leader portal access.
- **Key Features**:
  - **Unit Mapping Grid**: View all master units, their short codes, districts, and assigned leader contact emails.
  - **Leader Access Status Badges**: Real-time status for each unit leader indicating whether an invitation has been issued (`Invited` vs. `Not Invited`) and whether they have accessed the platform (`Logged In` with timestamp vs. `Never Logged In`).
  - **Single & Bulk Invitations**: Send an invitation email with a one-time password creation link to an individual unit leader or click **"Invite All Uninvited Leaders"** to provision WordPress accounts in bulk.
  - **Patrol Linking**: Reconcile unmatched patrols synced from OSM to existing master units or create new units with a single click.

#### E. Settings, Column Mapper & Access Control
- **Purpose**: Configure integration credentials, data backup/restore, flexi-record mappings, and access control rules.
- **Key Features**:
  - **OSM Connection**: Manage OAuth credentials, API endpoints, rate limits, and test connectivity.
  - **Access Control & Page Restrictions**: Restrict access to any WordPress page to specific user roles (e.g. `ems_parent`, `ems_leader`), enforce Tutor LMS route protection, and configure the **Unit Leader Dashboard Page**.
  - **Customizable Leader Invitation Email**: Customize the subject line and email body sent when unit leaders are invited, using template placeholders: `{{unit_name}}`, `{{set_password_url}}`, `{{dashboard_url}}`, `{{site_name}}`, and `{{user_email}}`.
  - **Portability & Backups**: Export or restore unified JSON snapshots containing all plugin options, master units, patrol links, signups, and custom table data.

---

### End-User Screens (Public Portal)

#### F. Participant & Parent Portal (`[ems-portal]`)
- **Purpose**: The central dashboard for explorers and parents to manage their expedition journey.
- **Key Features**:
  - **OSM Login**: Sign in securely using their Online Scout Manager (OSM) credentials.
  - **Child Selection**: Parents with multiple children in the program can select which child's records they want to view.
  - **Expedition Status**: View assigned expeditions, dates, teams, and safety details.
  - **Online Training**: Access and track progress of required courses hosted in Tutor LMS.
  - **Route Submissions**: Upload route card PDFs and GPX files. View feedback and approval status from the Leader-in-Charge.

#### G. Front-End Unit Leader Dashboard (`[ems-leader-dashboard]`)
- **Purpose**: A clean, read-only portal for local Explorer Scout Unit (ESU) leaders to track which explorers in their unit have registered.
- **Key Features**:
  - **Grouped Explorer Roster**: Explorers are grouped by Unit with unit headers and sorted alphabetically by first name.
  - **DofE Level Badges**: Visually distinct bronze, silver, and gold level indicators.
  - **Registration Completion Flags**: Clear checkmarks (`✓`) indicating whether the participant has registered for a Participant Place (Form 6) and/or submitted Expedition Preferences (Form 7), with expedition mode tags (e.g., *Hillwalking*, *Biking*, *Paddling*).
  - **OSM Sync Indicator**: Shows the Online Scout Manager sync status icon for explorers linked to an active OSM profile.
  - **Clean Presentation & Security**: Excludes sensitive medical notes, contact numbers, and parent details to adhere to data privacy standards. Clicking rows displays clean read-only data without opening administrative inspector panels.
  - **Theme Compatibility & Pagination**: Adopts the site's typography and table styling with responsive pagination across grouped explorer rows.

---

## 4. Forms & Registration

- **Expedition Signup Form**: Powering the entry point of the participant journey, parents fill out a Fluent Form to declare their child's DofE level, expedition preferences, and current First Aid status.
- **Reconciliation & Linking**: Submitted signups automatically flow into the **Admin Signups Board**, where they are validated against synced OSM records before participants are placed into teams.

---

## 5. Thematic Workflows

### Workflow 1: How a Participant Signs Up & Logs In
1. **Submit Signup**: The parent fills out the signup form on the website to register interest and submit details (including First Aid level).
2. **Account Linking**: The system checks the parent's OSM record. If the parent logs in for the first time, a secure parental account is generated, linking their children.
3. **Explorer Login**: When the explorer logs in using their own email via the OSM Login option, the system automatically links their profile and completes their account setup.
4. **Online Training**: Once logged in, the explorer gains access to Tutor LMS to complete pre-expedition training modules.

### Workflow 2: How Admins Plan an Expedition
1. **Sync Reference Data**: The admin syncs the latest explorer and event details from OSM via the settings dashboard.
2. **Define Events**: The admin creates new events (training, practice, or qualifying expeditions) with specific dates, transport modes, and codes.
3. **Link OSM Events**: EMS events are linked directly to OSM events to sync parent portal exposure.
4. **Build Teams**: Using the **Expedition Board**, the admin assigns explorers to teams (e.g., auto-generating sequential team codes like `H-SP1-1`). The board shows teammate preferences and flags visual alerts if first aid coverage is lacking.
5. **Publish & Sync**: Team assignments and safety flags are pushed back to OSM Flexi-records automatically.

### Workflow 3: How Volunteer Staff are Managed
1. **Volunteer Signup**: Adult volunteers visit the expedition portal and sign up for specific dates, checking off days or overnight stays they can cover.
2. **Admin Review**: On the **Volunteer Board**, the admin views all pending volunteer signups.
3. **Staff Confirmation**: The admin confirms the volunteers, which updates the calendar dashboard and ensures the Leader-in-Charge knows exactly who is supervising each stage of the route.

### Workflow 4: How Unit Leaders Are Invited & Access Their Dashboard
1. **Admin Sends Invitations**: In the **EMS → Reference → Unit Mapping** tab, the administrator reviews unit leaders and clicks **"Send Invite"** (or **"Invite All Uninvited Leaders"**).
2. **WordPress Account Creation & Notification**: The system provisions a WordPress user account with the `ems_leader` role and sends an email customized via **EMS Settings → Access Control** containing a secure one-time password setup link (`{{set_password_url}}`).
3. **Password Setup & Seamless Onboarding**: The leader clicks the link in their email and enters their desired password on the WordPress password reset screen.
4. **Automatic Sign-In & Redirection**: Upon submitting their new password, WordPress immediately logs them in, records their `ems_last_login_at` timestamp (updating the admin status to *Logged In*), and redirects them straight to the front-end **Unit Leader Dashboard** (`[ems-leader-dashboard]`).
5. **Subsequent Logins**: Any time the leader logs in via the standard login page in the future, they are automatically routed directly to their Leader Dashboard rather than the WordPress backend.

