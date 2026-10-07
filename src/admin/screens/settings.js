/**
 * Settings: safety switches, connection details and health checks.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { update } from '@wordpress/icons';

import { api } from '../api';
import { Badge, CopyField, ScreenHeader } from '../components';

const settings = window.viagentProductionSettings;

const STATUS_LABELS = {
	good: __( 'OK', 'viagent-production' ),
	info: __( 'Note', 'viagent-production' ),
	warning: __( 'Check', 'viagent-production' ),
	critical: __( 'Problem', 'viagent-production' ),
};

/**
 * Checks that need a real HTTP request, run from the browser: an AI app calls
 * the same URL, and WordPress loopback requests hang on single-threaded servers.
 *
 * @return {Promise<Object[]>} Check results.
 */
async function browserChecks() {
	const results = [];
	const request = ( url, options ) =>
		window.fetch( url, { credentials: 'omit', ...options } );

	try {
		const response = await request( settings.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: '{"jsonrpc":"2.0","id":1,"method":"ping"}',
		} );
		const ok = [ 401, 503 ].includes( response.status );
		results.push( {
			id: 'endpoint',
			status: ok ? 'good' : 'critical',
			label: __( 'Connection URL', 'viagent-production' ),
			message: ok
				? __(
						'The connection URL is online and requires a key.',
						'viagent-production'
					)
				: sprintf(
						/* translators: %d: HTTP status code */
						__(
							'The connection URL answered with an unexpected status (%d).',
							'viagent-production'
						),
						response.status
					),
			fix: ok
				? ''
				: __(
						'A security plugin, firewall or cache may be blocking /wp-json/. Allow requests to /wp-json/viagent-production/.',
						'viagent-production'
					),
		} );
	} catch {
		results.push( {
			id: 'endpoint',
			status: 'critical',
			label: __( 'Connection URL', 'viagent-production' ),
			message: __(
				'The connection URL could not be reached.',
				'viagent-production'
			),
			fix: __(
				'Check that the REST API is not disabled by a security plugin or firewall.',
				'viagent-production'
			),
		} );
		return results;
	}

	try {
		const response = await request( settings.probeUrl, {
			headers: { Authorization: 'Bearer viagent-production-probe' },
		} );
		const body = await response.json();
		results.push(
			body.authorization
				? {
						id: 'auth_header',
						status: 'good',
						label: __( 'Key header', 'viagent-production' ),
						message: __(
							'Your server passes the key header through to WordPress.',
							'viagent-production'
						),
					}
				: {
						id: 'auth_header',
						status: 'warning',
						label: __( 'Key header', 'viagent-production' ),
						message: __(
							'Your server removes the "Authorization" header, so apps sending the key that way are rejected. Apps can send it as "X-Viagent Production-Production-Key" instead.',
							'viagent-production'
						),
						fix: __(
							'Add this line to .htaccess above "# BEGIN WordPress": SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
							'viagent-production'
						),
					}
		);
	} catch {
		// Reachability is already reported above.
	}

	return results;
}

function HealthChecks() {
	const [ health, setHealth ] = useState( null );
	const [ error, setError ] = useState( null );

	const run = () => {
		setHealth( null );
		Promise.all( [ api.getHealth(), browserChecks() ] )
			.then( ( [ server, browser ] ) => {
				const [ first, ...rest ] = server.checks;
				// Keep the pause warning first, then permalinks, then reachability.
				const checks =
					first?.id === 'paused'
						? [ first, ...browser, ...rest ]
						: [ ...browser, ...server.checks ];
				setHealth( { ...server, checks } );
			} )
			.catch( ( e ) => setError( e.message ) );
	};

	useEffect( run, [] );

	return (
		<Card>
			<CardHeader>
				<h3>{ __( 'Connection check', 'viagent-production' ) }</h3>
				<Button
					variant="secondary"
					size="compact"
					icon={ update }
					onClick={ run }
					disabled={ ! health }
				>
					{ __( 'Run again', 'viagent-production' ) }
				</Button>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! health && ! error && <Spinner /> }
				{ health && (
					<ul className="viagent-production-health">
						{ health.checks.map( ( check ) => (
							<li
								key={ check.id }
								className={ `is-${ check.status }` }
							>
								<span className="viagent-production-health__status">
									{ STATUS_LABELS[ check.status ] }
								</span>
								<div>
									<strong>{ check.label }</strong>
									<p>{ check.message }</p>
									{ check.fix && (
										<p className="viagent-production-health__fix">
											<strong>
												{ __(
													'How to fix:',
													'viagent-production'
												) }
											</strong>{ ' ' }
											{ check.fix }
										</p>
									) }
								</div>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

export default function Settings( { state, onStateChange } ) {
	const [ error, setError ] = useState( null );

	const save = async ( data ) => {
		try {
			onStateChange( await api.updateSettings( data ) );
		} catch ( e ) {
			setError( e.message );
		}
	};

	return (
		<>
			<ScreenHeader
				title={ __( 'Settings', 'viagent-production' ) }
				description={ __(
					'Safety switches that apply to every connected AI app.',
					'viagent-production'
				) }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="viagent-production-settings">
				<Card>
					<CardHeader>
						<h3>{ __( 'Safety', 'viagent-production' ) }</h3>
					</CardHeader>
					<CardBody className="viagent-production-settings__toggles">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Pause all AI access',
								'viagent-production'
							) }
							help={ __(
								'Instantly blocks every connected app. Turn it off to resume — connections are kept.',
								'viagent-production'
							) }
							checked={ Boolean( state?.paused ) }
							onChange={ ( paused ) => save( { paused } ) }
						/>
						<div
							className={ `viagent-production-danger-toggle ${
								state?.allow_permanent_delete ? 'is-on' : ''
							}` }
						>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Allow permanent deletion',
									'viagent-production'
								) }
								help={ __(
									'Off (recommended): deleted posts go to the trash and media cannot be deleted. On: AI apps with delete access can skip the trash and delete media — this cannot be undone.',
									'viagent-production'
								) }
								checked={ Boolean(
									state?.allow_permanent_delete
								) }
								onChange={ ( value ) =>
									save( { allow_permanent_delete: value } )
								}
							/>
							{ state?.allow_permanent_delete && (
								<p className="viagent-production-danger-toggle__warning">
									{ __(
										'Permanent deletion is on. AI apps with delete access can remove things that cannot be undone.',
										'viagent-production'
									) }
								</p>
							) }
						</div>
						<div
							className={ `viagent-production-danger-toggle ${
								state?.allow_code ? 'is-on' : ''
							}` }
						>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Allow AI apps to change code',
									'viagent-production'
								) }
								help={ __(
									'Off (recommended): apps with Full control can read theme and plugin code and install from WordPress.org, but cannot write or delete files or install from a .zip link. On: they can edit theme and plugin code, build child themes and plugins, and install any .zip — code they write runs on your server with no safety checks.',
									'viagent-production'
								) }
								checked={ Boolean( state?.allow_code ) }
								onChange={ ( value ) =>
									save( { allow_code: value } )
								}
							/>
							{ state?.allow_code && (
								<p className="viagent-production-danger-toggle__warning">
									{ __(
										'Code changes are on. An AI with Full control can run any code on this site, which also lets it work around every other safety setting. Turn this off when you are done.',
										'viagent-production'
									) }
								</p>
							) }
						</div>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Advanced', 'viagent-production' ) }</h3>
					</CardHeader>
					<CardBody className="viagent-production-settings__toggles">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Compact tool list for all apps',
								'viagent-production'
							) }
							help={ __(
								'Shows AI apps 3 tools that look up the others, instead of the full list. Useful for apps or small models that struggle with many tools. Apps with a known tool limit, like Cursor, use this automatically when needed.',
								'viagent-production'
							) }
							checked={ Boolean( state?.compact_mode ) }
							onChange={ ( value ) =>
								save( { compact_mode: value } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Delete all Viagent Production data when the plugin is deleted',
								'viagent-production'
							) }
							help={ __(
								'Removes connections, activity history and settings when you delete the plugin from the Plugins screen. Deactivating the plugin never deletes anything.',
								'viagent-production'
							) }
							checked={ Boolean( state?.delete_data ) }
							onChange={ ( value ) =>
								save( { delete_data: value } )
							}
						/>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>
							{ __( 'Connection details', 'viagent-production' ) }
						</h3>
					</CardHeader>
					<CardBody>
						<CopyField
							label={ __(
								'MCP server URL (Streamable HTTP)',
								'viagent-production'
							) }
							value={ settings.endpoint }
						/>
						<p className="viagent-production-muted">
							{ __(
								'Apps authenticate with a connection key (Authorization: Bearer …), or with a WordPress Application Password.',
								'viagent-production'
							) }
						</p>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h3>{ __( 'Integrations', 'viagent-production' ) }</h3>
					</CardHeader>
					<CardBody>
						<p className="viagent-production-muted">
							{ __(
								'Extra tools appear automatically when these plugins are active. Manage them under Tools.',
								'viagent-production'
							) }
						</p>
						<ul className="viagent-production-integrations">
							{ ( state?.integrations || [] ).map( ( item ) => (
								<li key={ item.slug }>
									<span>{ item.name }</span>
									{ item.active ? (
										<Badge tone="success">
											{ __(
												'Active',
												'viagent-production'
											) }
										</Badge>
									) : (
										<Badge>
											{ __(
												'Not active',
												'viagent-production'
											) }
										</Badge>
									) }
								</li>
							) ) }
						</ul>
					</CardBody>
				</Card>

				<HealthChecks />
			</div>
		</>
	);
}
