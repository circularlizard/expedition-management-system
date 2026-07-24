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
  - *Constraint*: Parents are restricted from completing online training courses on behalf of their children.

### B. Explorers / Participants (`ems_explorer`)
- **Access Source**: Dynamically assigned when a young person logs in directly using their OSM member details.
- **Key Capabilities**:
  - Access required online training courses in Tutor LMS (access is strictly restricted to this role).
  - View personal expedition status, team codes, and safety alerts.
  - Submit route planning materials, including uploading GPX files and Route Card PDFs.
  - View status updates and feedback from the Leader-in-Charge.

### C. Leaders & Leaders-in-Charge (`ems_leader`)
- **Access Source**: Automatically assigned if the user administers any sections in OSM, or mapped dynamically on login.
- **Key Capabilities**:
  - Review, approve, or request changes on team route planning submissions.
  - Manage volunteer availability, confirm event staffing levels, and view staff calendars.
  - View team compositions, explorer lists, and safety details (such as First Aid coverage) for their assigned expeditions.

### D. Expedition Administrators (`administrator`)
- **Access Source**: Standard WordPress Administrator role.
- **Key Capabilities**:
  - Complete control over seasons, events (expeditions), and configuration options.
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
- **Purpose**: The main planning dashboard to group expeditions by **Seasons**, build **Teams**, and assign participants.
- **Key Features**:
  - **Seasons & Events**: Group training, practice, and qualifying expeditions under a specific season. Each event displays details like the Leader-in-Charge (LiC), locations, dates, and the linked OSM Event.
  - **Team Builder**: A modern interface to group participants into teams. It displays team sizes (ideally 4–7 members) and raises alerts if a team is too small or too large.
  - **Unassigned Pool**: View all synced participants who are not yet assigned to a team, making it easy to drag/drop them into place.
  - **First Aid Tracking**: Instantly displays the first aid qualifications of each team member to ensure safety compliance.

#### C. Volunteer Management Board
- **Purpose**: View and manage adult volunteer availability for expeditions.
- **Key Features**:
  - **Availability Breakdown**: Volunteers specify their availability (either the whole expedition or specific days/overnights).
  - **Approval Pipeline**: Admins or Leaders-in-Charge review pending signups and mark volunteers as *Confirmed* or *Pending*.
  - **Multi-View Calendars**: View volunteer coverage in a monthly calendar, look at specific expedition staff lists, or view an individual volunteer's season commitments.

#### D. Settings & Column Mapper
- **Purpose**: Configures mappings between custom fields in OSM (Flexi-records) and corresponding variables in EMS (such as First Aid status or Team assignments).

---

### End-User Screens (Public Portal)

#### E. Participant & Parent Portal
- **Purpose**: The central dashboard for explorers and parents to manage their expedition journey.
- **Key Features**:
  - **OSM Login**: Sign in securely using their Online Scout Manager (OSM) credentials.
  - **Child Selection**: Parents with multiple children in the program can select which child's records they want to view.
  - **Expedition Status**: View assigned expeditions, dates, teams, and safety details.
  - **Online Training**: Access and track progress of required courses hosted in Tutor LMS.
  - **Route Submissions**: Upload route card PDFs and GPX files. View feedback and approval status from the Leader-in-Charge.

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
2. **Define Season & Events**: The admin creates the new Season (e.g., "2026 Season") and adds events with specific dates, transport modes, and codes.
3. **Link OSM Events**: EMS events are linked directly to OSM events to sync parent portal exposure.
4. **Build Teams**: Using the **Expedition Board**, the admin assigns explorers to teams (e.g., auto-generating sequential team codes like `H-SP1-1`). The board shows teammate preferences and flags visual alerts if first aid coverage is lacking.
5. **Publish & Sync**: Team assignments and safety flags are pushed back to OSM Flexi-records automatically.

### Workflow 3: How Volunteer Staff are Managed
1. **Volunteer Signup**: Adult volunteers visit the expedition portal and sign up for specific dates, checking off days or overnight stays they can cover.
2. **Admin Review**: On the **Volunteer Board**, the admin views all pending volunteer signups.
3. **Staff Confirmation**: The admin confirms the volunteers, which updates the calendar dashboard and ensures the Leader-in-Charge knows exactly who is supervising each stage of the route.

