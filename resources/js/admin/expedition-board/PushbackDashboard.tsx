import React, { useState, useEffect } from 'react';

interface SectionInfo {
	name: string;
	type: string;
}

interface FlexiUpdate {
	scout_id: number;
	first_name: string;
	last_name: string;
	column: string;
	column_name: string;
	current_value: string;
	proposed_value: string;
}

interface EventInvite {
	scout_id: number;
	first_name: string;
	last_name: string;
	status: string;
	action: string;
}

interface ProposedEvent {
	event_id: number;
	proposed_invites: EventInvite[];
}

interface PreviewData {
	flexi_record: {
		exists: boolean;
		id: number | null;
		proposed_name: string;
		missing_columns: string[];
		updates: FlexiUpdate[];
	};
	events: ProposedEvent[];
	errors: string[];
}

export const PushbackDashboard: React.FC = () => {
	const config = (window as any).emsExpeditionBoard || { root_url: '', nonce: '', sections: {} };
	const sections: Record<string, SectionInfo> = config.sections || {};
	const sectionIds = Object.keys(sections);

	const [selectedSection, setSelectedSection] = useState<string>(sectionIds[0] || '');
	const [loading, setLoading] = useState<boolean>(false);
	const [preview, setPreview] = useState<PreviewData | null>(null);
	const [error, setError] = useState<string | null>(null);

	const fetchPreview = async (sectionId: string) => {
		if (!sectionId) return;
		setLoading(true);
		setError(null);
		try {
			const pushbackEl = document.getElementById('ems-pushback-root');
			const token = pushbackEl?.getAttribute('data-token') || '';
			const tokenParam = token ? `&access_token=${encodeURIComponent(token)}` : '';

			const response = await fetch(
				`${config.root_url}/admin/sync-preview?section_id=${sectionId}${tokenParam}`,
				{
					headers: {
						'X-WP-Nonce': config.nonce,
					},
				}
			);
			if (!response.ok) {
				const errData = await response.json();
				throw new Error(errData.message || 'Failed to fetch sync preview.');
			}
			const data: PreviewData = await response.json();
			setPreview(data);
		} catch (err: any) {
			setError(err.message || 'An error occurred.');
			setPreview(null);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (selectedSection) {
			fetchPreview(selectedSection);
		}
	}, [selectedSection]);

	const totalUpdatesCount = preview
		? preview.flexi_record.updates.length +
		  preview.events.reduce((acc, ev) => acc + ev.proposed_invites.filter(inv => inv.action === 'Invite').length, 0)
		: 0;

	return (
		<div>
			<div className="ems-pushback-dashboard">
				<div className="ems-flex ems-gap-6 ems-align-center" style={{ marginBottom: '20px' }}>
					<label htmlFor="section-select" style={{ fontWeight: 'bold', fontSize: '14px' }}>
						Select Section:
					</label>
					<select
						id="section-select"
						value={selectedSection}
						onChange={(e) => setSelectedSection(e.target.value)}
						className="postform"
					>
						<option value="">-- Choose Section --</option>
						{sectionIds.map((id) => (
							<option key={id} value={id}>
								{sections[id].name} ({sections[id].type})
							</option>
						))}
					</select>
					<button
						type="button"
						className="button"
						onClick={() => fetchPreview(selectedSection)}
						disabled={loading || !selectedSection}
					>
						Refresh Preview
					</button>
				</div>

				{loading && <p className="description">Generating OSM push-back sync preview...</p>}

				{error && (
					<div className="notice notice-error inline" style={{ margin: '15px 0' }}>
						<p>{error}</p>
					</div>
				)}

				{preview && (
					<div className="ems-preview-container">
						{preview.errors && preview.errors.length > 0 && (
							<div className="notice notice-warning inline" style={{ margin: '15px 0' }}>
								<ul>
									{preview.errors.map((err, idx) => (
										<li key={idx}>{err}</li>
									))}
								</ul>
							</div>
						)}

						{/* Flexi-record Preview */}
						<div className="card" style={{ padding: '15px', margin: '20px 0', maxWidth: '100%' }}>
							<h2>Flexi-Record: {preview.flexi_record.proposed_name}</h2>
							<p className="description">
								Status:{' '}
								<strong>
									{preview.flexi_record.exists
										? `Linked (ID: ${preview.flexi_record.id})`
										: 'Will be created on sync'}
								</strong>
							</p>

							{preview.flexi_record.missing_columns &&
								preview.flexi_record.missing_columns.length > 0 && (
									<div className="notice notice-warning inline" style={{ margin: '10px 0' }}>
										<p>
											<strong>Missing Columns to Create:</strong>{' '}
											{preview.flexi_record.missing_columns.join(', ')}
										</p>
									</div>
								)}

							<h3>Proposed Values Updates</h3>
							{preview.flexi_record.updates && preview.flexi_record.updates.length > 0 ? (
								<table className="wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th>Scout ID</th>
											<th>Name</th>
											<th>Column</th>
											<th>Current OSM Value</th>
											<th>Proposed EMS Value</th>
										</tr>
									</thead>
									<tbody>
										{preview.flexi_record.updates.map((up, idx) => (
											<tr key={idx}>
												<td>{up.scout_id}</td>
												<td>
													{up.first_name} {up.last_name}
												</td>
												<td>
													<strong>{up.column_name}</strong> ({up.column})
												</td>
												<td>
													<span style={{ color: '#c00', textDecoration: 'line-through' }}>
														{up.current_value || '—'}
													</span>
												</td>
												<td>
													<span style={{ color: '#090', fontWeight: 'bold' }}>
														{up.proposed_value}
													</span>
												</td>
											</tr>
										))}
									</tbody>
								</table>
							) : (
								<p className="description">No flexi-record field discrepancies found.</p>
							)}
						</div>

						{/* Event Attendance Preview */}
						<div className="card" style={{ padding: '15px', margin: '20px 0', maxWidth: '100%' }}>
							<h2>Event Attendance Invitations</h2>
							{preview.events && preview.events.length > 0 ? (
								preview.events.map((ev, idx) => (
									<div key={idx} style={{ marginBottom: '20px' }}>
										<h3>OSM Event ID: {ev.event_id}</h3>
										{ev.proposed_invites && ev.proposed_invites.length > 0 ? (
											<table className="wp-list-table widefat fixed striped">
												<thead>
													<tr>
														<th>Scout ID</th>
														<th>Name</th>
														<th>Current Status</th>
														<th>Proposed Action</th>
													</tr>
												</thead>
												<tbody>
													{ev.proposed_invites.map((inv, invIdx) => (
														<tr key={invIdx}>
															<td>{inv.scout_id}</td>
															<td>
																{inv.first_name} {inv.last_name}
															</td>
															<td>
																<span className={`ems-status-badge ems-status-badge--${
																	inv.status.toLowerCase() === 'yes' || inv.status.toLowerCase() === 'attending' ? 'success' :
																	inv.status.toLowerCase() === 'no' || inv.status.toLowerCase() === 'declined' ? 'danger' :
																	inv.status.toLowerCase() === 'invited' ? 'pending' : 'draft'
																}`}>
																	{inv.status}
																</span>
															</td>
															<td>
																{inv.action !== 'None' ? (
																	<span className={`ems-status-badge ems-status-badge--${
																		inv.action.toLowerCase().includes('declined') || inv.action.toLowerCase().includes('re-invite') ? 'danger' : 'pending'
																	}`}>
																		{inv.action}
																	</span>
																) : (
																	<span className="description" style={{ color: '#666' }}>
																		No change (Already synced)
																	</span>
																)}
															</td>
														</tr>
													))}
												</tbody>
											</table>
										) : (
											<p className="description">No new invitations to send for this event.</p>
										)}
									</div>
								))
							) : (
								<p className="description">No event attendance updates proposed.</p>
							)}
						</div>

						{/* Actions */}
						<div style={{ marginTop: '20px', display: 'flex', alignItems: 'center', gap: '15px' }}>
							<button type="button" className="button button-primary" disabled>
								Execute Push-back Sync ({totalUpdatesCount} changes)
							</button>
							<span className="description" style={{ color: '#b92c28' }}>
								⚠ Push-back writes are disabled in Read-Only Preview Mode
							</span>
						</div>
					</div>
				)}
			</div>
		</div>
	);
};

export default PushbackDashboard;
