/**
 * Connections: every AI app that can access the site, with status and revoke.
 */
import { Button, Card, Modal, Notice, Spinner } from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import { api } from '../api';
import {
	Badge,
	ClientMark,
	ScreenHeader,
	clientFor,
	levelTone,
} from '../components';

const settings = window.viagentProductionSettings;

function ConnectionStatus( { connection, onHelp } ) {
	if ( connection.last_used_at ) {
		return (
			<span className="viagent-production-conn__status is-connected">
				<span className="viagent-production-dot is-success" aria-hidden="true" />
				{ sprintf(
					/* translators: %s: relative time */
					__( 'Active %s', 'viagent-production' ),
					humanTimeDiff( connection.last_used_at )
				) }
			</span>
		);
	}
	return (
		<span className="viagent-production-conn__status is-waiting">
			<span className="viagent-production-dot is-warning" aria-hidden="true" />
			{ __( 'Not connected yet', 'viagent-production' ) }
			<Button variant="link" onClick={ onHelp }>
				{ __( 'How to finish', 'viagent-production' ) }
			</Button>
		</span>
	);
}

export default function Connections( { navigate } ) {
	const [ connections, setConnections ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ revoking, setRevoking ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		api
			.listConnections()
			.then( setConnections )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
	}, [] );

	const revoke = async () => {
		setBusy( true );
		try {
			await ( revoking.type === 'oauth'
				? api.revokeGrant( revoking.id )
				: api.revokeConnection( revoking.id ) );
			setRevoking( null );
			await load();
		} catch ( e ) {
			setError( e.message );
		}
		setBusy( false );
	};

	const newButton = (
		<Button
			variant="primary"
			icon={ plus }
			onClick={ () => navigate( 'connect' ) }
		>
			{ __( 'Connect an app', 'viagent-production' ) }
		</Button>
	);

	return (
		<>
			<ScreenHeader
				title={ __( 'Connections', 'viagent-production' ) }
				description={ __(
					'AI apps that can access your site. Revoke any of them at any time — they are disconnected immediately.',
					'viagent-production'
				) }
				actions={ newButton }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ connections === null && <Spinner /> }

			{ connections && connections.length === 0 && (
				<Card className="viagent-production-empty">
					<span className="viagent-production-empty__icon" aria-hidden="true">
						✦
					</span>
					<h3>{ __( 'No AI apps connected yet', 'viagent-production' ) }</h3>
					<p>
						{ __(
							'Connect Claude, ChatGPT, Cursor or another AI app in about a minute.',
							'viagent-production'
						) }
					</p>
					{ newButton }
				</Card>
			) }

			{ connections && connections.length > 0 && (
				<Card className="viagent-production-list">
					{ connections.map( ( connection ) => {
						const client = clientFor( connection.client );
						return (
							<div
								className="viagent-production-conn"
								key={ `${ connection.type }-${ connection.id }` }
							>
								<ClientMark client={ client } />
								<div className="viagent-production-conn__main">
									<strong>{ connection.name }</strong>
									<span
										className="viagent-production-muted"
										title={
											connection.key_prefix
												? `${ connection.key_prefix }…`
												: undefined
										}
									>
										{ connection.type === 'oauth'
											? __(
													'Signed in with WordPress',
													'viagent-production'
												)
											: __(
													'Connected with a key',
													'viagent-production'
												) }
										{ ' · ' }
										{ sprintf(
											/* translators: %s: user name */
											__( 'acts as %s', 'viagent-production' ),
											connection.user
										) }
									</span>
								</div>
								<div className="viagent-production-conn__badges">
									<Badge
										tone={ levelTone(
											connection.access_level
										) }
									>
										{
											settings.levels[
												connection.access_level
											]
										}
									</Badge>
									{ connection.access_level !== 'read' &&
										connection.draft_only && (
											<Badge>
												{ __(
													'Drafts only',
													'viagent-production'
												) }
											</Badge>
										) }
									{ connection.compact && (
										<Badge tone="info">
											{ __( 'Compact', 'viagent-production' ) }
										</Badge>
									) }
								</div>
								<ConnectionStatus
									connection={ connection }
									onHelp={ () => navigate( 'connect' ) }
								/>
								<Button
									className="viagent-production-conn__revoke"
									variant="tertiary"
									isDestructive
									size="compact"
									onClick={ () => setRevoking( connection ) }
								>
									{ __( 'Revoke', 'viagent-production' ) }
								</Button>
							</div>
						);
					} ) }
				</Card>
			) }

			{ revoking && (
				<Modal
					title={ __( 'Revoke this connection?', 'viagent-production' ) }
					onRequestClose={ () => setRevoking( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: connection name */
							__(
								'“%s” will immediately lose access to your site. You can create a new connection later.',
								'viagent-production'
							),
							revoking.name
						) }
					</p>
					<div className="viagent-production-actions">
						<Button
							variant="tertiary"
							onClick={ () => setRevoking( null ) }
						>
							{ __( 'Cancel', 'viagent-production' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							disabled={ busy }
							onClick={ revoke }
						>
							{ __( 'Revoke access', 'viagent-production' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
