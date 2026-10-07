/**
 * Connect wizard: pick an app → choose what it may do → follow tailored steps.
 */
import {
	Button,
	Card,
	CardBody,
	Modal,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { arrowLeft, check, external } from '@wordpress/icons';

import { api } from '../api';
import {
	Badge,
	ClientMark,
	CodeBlock,
	CopyButton,
	CopyField,
} from '../components';
import { deeplink, fillTemplate } from '../snippets';

const settings = window.viagentProductionSettings;

const GROUPS = [
	{ id: 'app', label: __( 'Chat apps', 'viagent-production' ) },
	{ id: 'editor', label: __( 'Code editors', 'viagent-production' ) },
	{ id: 'cli', label: __( 'Terminal agents', 'viagent-production' ) },
	{ id: 'other', label: __( 'Something else', 'viagent-production' ) },
];

const ACCESS = [
	{
		id: 'read',
		title: __( 'Read only', 'viagent-production' ),
		description: __(
			'Can look at your posts, pages, media and settings. Cannot change anything.',
			'viagent-production'
		),
		example: __( '“Summarize my latest posts”', 'viagent-production' ),
	},
	{
		id: 'content',
		title: __( 'Write content', 'viagent-production' ),
		description: __(
			'Can write and edit posts and pages, upload images, and manage categories and comments.',
			'viagent-production'
		),
		example: __( '“Draft a blog post about our spring sale”', 'viagent-production' ),
		recommended: true,
	},
	{
		id: 'admin',
		title: __( 'Full control', 'viagent-production' ),
		description: __(
			'Everything above plus menus, site settings, plugins, themes, theme and plugin code, and user accounts.',
			'viagent-production'
		),
		example: __( '“Install WooCommerce and build me a child theme”', 'viagent-production' ),
	},
];

const EXAMPLE_PROMPTS = [
	__(
		'What is my WordPress site about? Give me a quick overview.',
		'viagent-production'
	),
	__(
		'Write a 600-word blog post about why our customers love us and save it as a draft.',
		'viagent-production'
	),
	__( 'Find my posts that have no featured image.', 'viagent-production' ),
];

function stepClass( index, step ) {
	if ( index + 1 === step ) {
		return 'is-current';
	}
	return index + 1 < step ? 'is-done' : '';
}

function Steps( { step } ) {
	const labels = [
		__( 'Choose your AI app', 'viagent-production' ),
		__( 'Choose what it can do', 'viagent-production' ),
		__( 'Connect', 'viagent-production' ),
	];
	return (
		<ol className="viagent-production-steps">
			{ labels.map( ( label, index ) => (
				<li key={ label } className={ stepClass( index, step ) }>
					<span className="viagent-production-steps__number">{ index + 1 }</span>
					{ label }
				</li>
			) ) }
		</ol>
	);
}

/**
 * Connection status per catalog slug: "connected" once an app has made a
 * request, "waiting" when it was set up but never connected.
 *
 * @param {Object[]} connections Connections from the API.
 * @return {Object} Map of slug → { status, connection }.
 */
function statusByClient( connections ) {
	const map = {};
	( connections || [] ).forEach( ( connection ) => {
		const status = connection.last_used_at ? 'connected' : 'waiting';
		const current = map[ connection.client ];
		if (
			! current ||
			( status === 'connected' && current.status !== 'connected' )
		) {
			map[ connection.client ] = { status, connection };
		}
	} );
	return map;
}

function ClientStatus( { status } ) {
	if ( ! status ) {
		return null;
	}
	return status.status === 'connected' ? (
		<span className="viagent-production-client__status is-connected">
			{ __( 'Connected', 'viagent-production' ) }
		</span>
	) : (
		<span className="viagent-production-client__status is-waiting">
			{ __( 'Set up · not connected yet', 'viagent-production' ) }
		</span>
	);
}

function ChooseClient( { onSelect, statuses, onHelp } ) {
	const waiting = settings.clients.filter(
		( client ) => statuses[ client.slug ]?.status === 'waiting'
	);

	return (
		<div className="viagent-production-client-groups">
			{ waiting.map( ( client ) => (
				<Notice
					key={ client.slug }
					status="warning"
					isDismissible={ false }
					className="viagent-production-waiting-notice"
					actions={ [
						{
							label: __( 'Show me how to finish', 'viagent-production' ),
							onClick: () => onHelp( client ),
							variant: 'primary',
						},
					] }
				>
					{ sprintf(
						/* translators: %s: app name */
						__(
							'%s is set up but hasn’t connected to your site yet.',
							'viagent-production'
						),
						client.name
					) }
				</Notice>
			) ) }

			{ GROUPS.map( ( group ) => {
				const clients = settings.clients.filter(
					( client ) => client.group === group.id
				);
				if ( ! clients.length ) {
					return null;
				}
				return (
					<section key={ group.id }>
						<h3 className="viagent-production-group-title">{ group.label }</h3>
						<div className="viagent-production-client-grid">
							{ clients.map( ( client ) => {
								const soon =
									client.auth === 'oauth' && ! settings.oauth;
								let badge = null;
								if ( soon ) {
									badge = (
										<Badge>
											{ __( 'Coming soon', 'viagent-production' ) }
										</Badge>
									);
								} else if ( client.auth === 'oauth' ) {
									badge = (
										<Badge tone="success">
											{ __( 'Sign in', 'viagent-production' ) }
										</Badge>
									);
								} else if ( client.deeplink ) {
									badge = (
										<Badge tone="success">
											{ __( 'One-click', 'viagent-production' ) }
										</Badge>
									);
								}
								return (
									<button
										key={ client.slug }
										type="button"
										className={ `viagent-production-client ${
											statuses[ client.slug ]
												? `has-${ statuses[ client.slug ].status }`
												: ''
										}` }
										disabled={ soon }
										onClick={ () => onSelect( client ) }
									>
										<span className="viagent-production-client__top">
											<ClientMark client={ client } />
											{ badge }
										</span>
										<strong className="viagent-production-client__name">
											{ client.name }
										</strong>
										<span className="viagent-production-client__tagline">
											{ client.tagline }
										</span>
										<span className="viagent-production-client__footer">
											<ClientStatus
												status={
													statuses[ client.slug ]
												}
											/>
											<span
												className="viagent-production-client__arrow"
												aria-hidden="true"
											>
												→
											</span>
										</span>
									</button>
								);
							} ) }
						</div>
					</section>
				);
			} ) }
		</div>
	);
}

/**
 * First message to send, naming the tools so the AI uses them.
 *
 * @param {Object} client Catalog entry.
 * @return {string} Prompt.
 */
function firstPrompt( client ) {
	const source =
		client.auth === 'oauth'
			? sprintf(
					/* translators: %s: site name */
					__( 'my “%s” connector', 'viagent-production' ),
					settings.siteName
				)
			: sprintf(
					/* translators: %s: server name */
					__( 'the “%s” tools', 'viagent-production' ),
					settings.serverName
				);
	return sprintf(
		/* translators: %s: connector or tools name */
		__(
			'Use %s to look at my WordPress site and give me a short overview: the site name, how many posts and pages it has, and which theme it uses.',
			'viagent-production'
		),
		source
	);
}

/**
 * A numbered step section.
 *
 * @param {Object}  props
 * @param {number}  props.number   Step number.
 * @param {string}  props.title    Title.
 * @param {Element} props.children Content.
 */
function Section( { number, title, children } ) {
	return (
		<section className="viagent-production-section">
			<h3 className="viagent-production-section__title">
				<span className="viagent-production-section__number">{ number }</span>
				{ title }
			</h3>
			<div className="viagent-production-section__body">{ children }</div>
		</section>
	);
}

/**
 * "Check it's connected" + "Send your first message" steps.
 *
 * @param {Object}  props
 * @param {Object}  props.client  Catalog entry.
 * @param {number}  props.start   Number of the first section.
 * @param {boolean} props.inModal Shown in the help dialog (no live status).
 */
function FinishSteps( { client, start, inModal = false } ) {
	const prompt = firstPrompt( client );
	return (
		<>
			{ client.verify && (
				<Section
					number={ start }
					title={ sprintf(
						/* translators: %s: app name */
						__( 'Check that %s can see your site', 'viagent-production' ),
						client.name
					) }
				>
					<p>{ client.verify }</p>
				</Section>
			) }
			<Section
				number={ client.verify ? start + 1 : start }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Send this first message in %s', 'viagent-production' ),
					client.name
				) }
			>
				<div className="viagent-production-first-prompt">
					<p>{ prompt }</p>
					<CopyButton
						text={ prompt }
						label={ __( 'Copy message', 'viagent-production' ) }
					/>
				</div>
				<p className="viagent-production-muted">
					{ inModal
						? __(
								'The first time, your AI asks for permission to use your site’s tools — choose “Allow”. Then come back here: the app will show as Connected.',
								'viagent-production'
							)
						: __(
								'The first time, your AI asks for permission to use your site’s tools — choose “Allow”. The box below turns green as soon as it connects.',
								'viagent-production'
							) }
				</p>
			</Section>
		</>
	);
}

/**
 * Help for an app that was set up but never connected (key not shown again).
 *
 * @param {Object}     props
 * @param {Object}     props.client  Catalog entry.
 * @param {() => void} props.onClose Close handler.
 * @param {() => void} props.onRedo  Start a fresh setup.
 */
function HelpModal( { client, onClose, onRedo } ) {
	const bridge = ( client.template || '' ).includes( 'mcp-remote' );
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: app name */
				__( 'Finish connecting %s', 'viagent-production' ),
				client.name
			) }
			onRequestClose={ onClose }
			className="viagent-production-help-modal"
		>
			<div className="viagent-production-connect-steps">
				{ settings.devCert && bridge && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This site runs on your computer and uses a development security certificate. Make sure the "env" section of your configuration also contains this line, then restart the app:',
							'viagent-production'
						) }
						<code className="viagent-production-inline-code">
							&quot;NODE_TLS_REJECT_UNAUTHORIZED&quot;:
							&quot;0&quot;
						</code>
					</Notice>
				) }
				<FinishSteps client={ client } start={ 1 } inModal />
				<p className="viagent-production-muted">
					{ __(
						'Still not working? Create a fresh connection and follow the steps again — the old one can be revoked under Connections.',
						'viagent-production'
					) }
				</p>
				<div className="viagent-production-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close', 'viagent-production' ) }
					</Button>
					<Button variant="secondary" onClick={ onRedo }>
						{ __( 'Set it up again', 'viagent-production' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

function ChooseAccess( { client, onBack, onCreated } ) {
	const [ level, setLevel ] = useState( 'content' );
	const [ draftOnly, setDraftOnly ] = useState( true );
	const [ name, setName ] = useState( client.name );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const selectLevel = ( id ) => {
		setLevel( id );
		setDraftOnly( id !== 'admin' );
	};

	const create = async () => {
		setBusy( true );
		setError( null );
		try {
			const connection = await api.createConnection( {
				name: name || client.name,
				client: client.slug,
				access_level: level,
				draft_only: level === 'read' ? true : draftOnly,
			} );
			onCreated( connection );
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	return (
		<div className="viagent-production-access">
			<div
				className="viagent-production-access__cards"
				role="group"
				aria-label={ __( 'Access level', 'viagent-production' ) }
			>
				{ ACCESS.map( ( option ) => (
					<button
						key={ option.id }
						type="button"
						aria-pressed={ level === option.id }
						className={ `viagent-production-access-card ${
							level === option.id ? 'is-selected' : ''
						}` }
						onClick={ () => selectLevel( option.id ) }
					>
						<span className="viagent-production-access-card__title">
							{ option.title }
							{ option.recommended && (
								<Badge tone="success">
									{ __( 'Recommended', 'viagent-production' ) }
								</Badge>
							) }
						</span>
						<span className="viagent-production-access-card__description">
							{ option.description }
						</span>
						<span className="viagent-production-access-card__example">
							{ option.example }
						</span>
					</button>
				) ) }
			</div>

			{ level !== 'read' && (
				<Card className="viagent-production-access__options">
					<CardBody>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Drafts only (safest)', 'viagent-production' ) }
							help={
								draftOnly
									? __(
											'The AI can create and edit drafts, but cannot publish, delete, or change anything visitors already see. You review and publish.',
											'viagent-production'
										)
									: __(
											'The AI can publish and delete directly. Deleted items go to the trash, and you can undo changes from the Activity screen.',
											'viagent-production'
										)
							}
							checked={ draftOnly }
							onChange={ setDraftOnly }
						/>
					</CardBody>
				</Card>
			) }

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Connection name', 'viagent-production' ) }
				help={ __(
					'Helps you recognise it later, e.g. “Claude on my laptop”.',
					'viagent-production'
				) }
				value={ name }
				onChange={ setName }
			/>

			<p className="viagent-production-muted">
				{ sprintf(
					/* translators: %s: user display name */
					__(
						'The AI will act as you (%s), so it can never do more than your own account can. With Full control it can manage plugins, themes, their code and users; WordPress core updates and the site address stay with you.',
						'viagent-production'
					),
					settings.user
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div className="viagent-production-actions">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					onClick={ onBack }
				>
					{ __( 'Back', 'viagent-production' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ create }
				>
					{ __( 'Create connection', 'viagent-production' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Polls until the app has connected, then celebrates.
 *
 * @param {Object}                 props
 * @param {() => Promise<boolean>} props.isConnected Async check.
 * @param {() => void}             props.onDone      Done handler.
 * @param {() => void}             props.onAnother   "Connect another app" handler.
 */
function WaitForConnection( { isConnected, onDone, onAnother } ) {
	const [ connected, setConnected ] = useState( false );

	useEffect( () => {
		if ( connected ) {
			return;
		}
		const timer = setInterval( async () => {
			try {
				if ( await isConnected() ) {
					setConnected( true );
				}
			} catch {
				// Keep waiting; the connection may have been revoked elsewhere.
			}
		}, 3000 );
		return () => clearInterval( timer );
	}, [ connected, isConnected ] );

	if ( ! connected ) {
		return (
			<div className="viagent-production-waiting">
				<Spinner />
				<div>
					<strong>
						{ __(
							'Waiting for your AI app to connect…',
							'viagent-production'
						) }
					</strong>
					<p>
						{ __(
							'Finish the steps above and send the first message. This page updates by itself.',
							'viagent-production'
						) }
					</p>
				</div>
			</div>
		);
	}

	return (
		<div className="viagent-production-success">
			<span className="viagent-production-success__icon" aria-hidden="true">
				✓
			</span>
			<h3>
				{ __(
					'Connected! Your AI can now work on your site.',
					'viagent-production'
				) }
			</h3>
			<p>{ __( 'Try asking it:', 'viagent-production' ) }</p>
			<ul className="viagent-production-prompts">
				{ EXAMPLE_PROMPTS.map( ( prompt ) => (
					<li key={ prompt }>
						<span>{ prompt }</span>
						<CopyButton text={ prompt } />
					</li>
				) ) }
			</ul>
			<p className="viagent-production-muted">
				{ __(
					'Tip: many apps also offer ready-made tasks from your site, such as “Write a blog post” or “SEO check-up” — look in the “+” or / menu.',
					'viagent-production'
				) }
			</p>
			<div className="viagent-production-actions">
				<Button variant="secondary" onClick={ onAnother }>
					{ __( 'Connect another app', 'viagent-production' ) }
				</Button>
				<Button variant="primary" onClick={ onDone }>
					{ __( 'Done', 'viagent-production' ) }
				</Button>
			</div>
		</div>
	);
}

function ConnectSteps( { client, connection, onDone, onAnother } ) {
	const isConnected = useCallback(
		async () =>
			Boolean(
				( await api.getConnection( connection.id ) ).last_used_at
			),
		[ connection.id ]
	);
	const bridge = ( client.template || '' ).includes( 'mcp-remote' );
	const values = {
		url: settings.endpoint,
		key: connection.key,
		name: settings.serverName,
		// Node.js does not trust local development certificates.
		localEnv:
			settings.devCert && bridge
				? ',\n        "NODE_TLS_REJECT_UNAUTHORIZED": "0"'
				: '',
	};
	const snippet = fillTemplate( client.template, values );
	const link = client.deeplink ? deeplink( client.deeplink, values ) : null;
	const formatLabel = {
		command: __( 'Command', 'viagent-production' ),
		json: 'JSON',
		toml: 'TOML',
	}[ client.format ];

	return (
		<div className="viagent-production-connect-steps">
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Your connection key is shown only once. It is already included below — copy it now if you need it elsewhere. Treat it like a password.',
					'viagent-production'
				) }
			</Notice>

			<CopyField
				label={ __( 'Connection key', 'viagent-production' ) }
				value={ connection.key }
				secret
			/>

			{ client.needs && (
				<Notice status="info" isDismissible={ false }>
					{ client.needs }
				</Notice>
			) }

			<Section
				number={ 1 }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Add your site to %s', 'viagent-production' ),
					client.name
				) }
			>
				{ link && (
					<div className="viagent-production-deeplink">
						<Button variant="primary" href={ link } icon={ check }>
							{ sprintf(
								/* translators: %s: app name */
								__( 'Add to %s', 'viagent-production' ),
								client.name
							) }
						</Button>
						<span className="viagent-production-muted">
							{ __(
								'Opens the app and asks you to confirm.',
								'viagent-production'
							) }
						</span>
					</div>
				) }

				<ol className="viagent-production-instructions">
					{ client.steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>

				<CodeBlock
					code={ snippet }
					caption={
						client.file
							? `${ formatLabel } · ${ client.file }`
							: formatLabel
					}
				/>

				<details className="viagent-production-details">
					<summary>
						{ __( 'Manual setup details', 'viagent-production' ) }
					</summary>
					<CopyField
						label={ __(
							'Server URL (Streamable HTTP)',
							'viagent-production'
						) }
						value={ settings.endpoint }
					/>
					<CopyField
						label={ __( 'Header', 'viagent-production' ) }
						value={ `Authorization: Bearer ${ connection.key }` }
					/>
					<p className="viagent-production-muted">
						{ __(
							'If your server strips the Authorization header, send the key as “X-Viagent Production-Production-Key” instead.',
							'viagent-production'
						) }
					</p>
					{ client.docs && (
						<Button
							variant="link"
							href={ client.docs }
							target="_blank"
							icon={ external }
							iconPosition="right"
						>
							{ sprintf(
								/* translators: %s: app name */
								__( '%s MCP documentation', 'viagent-production' ),
								client.name
							) }
						</Button>
					) }
				</details>
				{ settings.devCert && bridge && (
					<p className="viagent-production-muted">
						{ __(
							'Because this site runs on your computer, the configuration includes a line that lets the bridge accept its local security certificate. Live sites don’t need it.',
							'viagent-production'
						) }
					</p>
				) }
				{ settings.devCert && client.local_tip && (
					<Notice status="info" isDismissible={ false }>
						{ client.local_tip }
					</Notice>
				) }
			</Section>

			<FinishSteps client={ client } start={ 2 } />

			<WaitForConnection
				isConnected={ isConnected }
				onDone={ onDone }
				onAnother={ onAnother }
			/>
		</div>
	);
}

function OAuthSteps( { client, onBack, onDone, onAnother } ) {
	// Allow for a little clock difference between browser and server.
	const [ startedAt ] = useState( () =>
		new Date( Date.now() - 30000 ).toISOString()
	);

	// Connected once a new sign-in (OAuth grant) appears after this screen opened.
	const isConnected = useCallback(
		async () =>
			( await api.listConnections() ).some(
				( item ) =>
					item.type === 'oauth' &&
					item.created_at &&
					item.created_at >= startedAt.slice( 0, 19 )
			),
		[ startedAt ]
	);

	return (
		<div className="viagent-production-connect-steps">
			{ settings.isLocal && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: app name */
						__(
							'This site runs on your computer, so %s (which runs in the cloud) cannot reach it. Publish the site or use a Studio preview or tunnel first — or connect an app on this computer instead, such as Claude Code, Claude Desktop or Cursor.',
							'viagent-production'
						),
						client.name
					) }
				</Notice>
			) }

			<Section
				number={ 1 }
				title={ sprintf(
					/* translators: %s: app name */
					__( 'Add your site to %s', 'viagent-production' ),
					client.name
				) }
			>
				<CopyField
					label={ __( 'Your connector URL', 'viagent-production' ) }
					value={ settings.endpoint }
				/>

				<ol className="viagent-production-instructions">
					{ client.steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>

				<p className="viagent-production-muted">
					{ __(
						'No key needed: you’ll sign in to WordPress and choose what the app may do. You can disconnect it any time.',
						'viagent-production'
					) }
				</p>

				{ client.needs && (
					<Notice status="info" isDismissible={ false }>
						{ client.needs }
					</Notice>
				) }
				{ client.docs && (
					<Button
						variant="link"
						href={ client.docs }
						target="_blank"
						icon={ external }
						iconPosition="right"
					>
						{ sprintf(
							/* translators: %s: app name */
							__( '%s’s official setup guide', 'viagent-production' ),
							client.name
						) }
					</Button>
				) }
			</Section>

			<FinishSteps client={ client } start={ 2 } />

			<WaitForConnection
				isConnected={ isConnected }
				onDone={ onDone }
				onAnother={ onAnother }
			/>

			<div className="viagent-production-actions">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					onClick={ onBack }
				>
					{ __( 'Back', 'viagent-production' ) }
				</Button>
			</div>
		</div>
	);
}

export default function Connect( { navigate } ) {
	const [ client, setClient ] = useState( null );
	const [ connection, setConnection ] = useState( null );
	const [ statuses, setStatuses ] = useState( {} );
	const [ helpFor, setHelpFor ] = useState( null );

	useEffect( () => {
		if ( ! client ) {
			api.listConnections()
				.then( ( list ) => setStatuses( statusByClient( list ) ) )
				.catch( () => {} );
		}
	}, [ client ] );

	const isOAuth = client?.auth === 'oauth';
	let step = 1;
	if ( client ) {
		step = connection || isOAuth ? 3 : 2;
	}

	const reset = () => {
		setClient( null );
		setConnection( null );
	};

	const titles = {
		1: __( 'Which AI app do you want to connect?', 'viagent-production' ),
		2: sprintf(
			/* translators: %s: app name */
			__( 'What should %s be allowed to do?', 'viagent-production' ),
			client?.name
		),
		3: sprintf(
			/* translators: %s: app name */
			__( 'Connect %s', 'viagent-production' ),
			client?.name
		),
	};

	return (
		<div className="viagent-production-connect">
			<Steps step={ step } />
			<Card>
				<CardBody className="viagent-production-connect__body">
					<div className="viagent-production-connect__title">
						{ client && <ClientMark client={ client } /> }
						<h2>{ titles[ step ] }</h2>
					</div>

					{ step === 1 && (
						<ChooseClient
							onSelect={ setClient }
							statuses={ statuses }
							onHelp={ setHelpFor }
						/>
					) }
					{ step === 2 && (
						<ChooseAccess
							client={ client }
							onBack={ reset }
							onCreated={ setConnection }
						/>
					) }
					{ step === 3 && isOAuth && (
						<OAuthSteps
							client={ client }
							onBack={ reset }
							onDone={ () => navigate( 'connections' ) }
							onAnother={ reset }
						/>
					) }
					{ step === 3 && ! isOAuth && (
						<ConnectSteps
							client={ client }
							connection={ connection }
							onDone={ () => navigate( 'connections' ) }
							onAnother={ reset }
						/>
					) }
				</CardBody>
			</Card>
			{ helpFor && (
				<HelpModal
					client={ helpFor }
					onClose={ () => setHelpFor( null ) }
					onRedo={ () => {
						setHelpFor( null );
						setClient( helpFor );
					} }
				/>
			) }
		</div>
	);
}
