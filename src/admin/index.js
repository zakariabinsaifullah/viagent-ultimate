/**
 * Viagent Production admin app.
 */
import { Button } from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { api } from './api';
import Activity from './screens/activity';
import Connect from './screens/connect';
import Connections from './screens/connections';
import Overview from './screens/overview';
import Settings from './screens/settings';
import Tools from './screens/tools';
import './admin.scss';

const settings = window.viagentProductionSettings;

const TABS = [
	{ id: 'overview', label: __( 'Overview', 'viagent-production' ) },
	{ id: 'connect', label: __( 'Connect an app', 'viagent-production' ) },
	{ id: 'connections', label: __( 'Connections', 'viagent-production' ) },
	{ id: 'tools', label: __( 'Tools', 'viagent-production' ) },
	{ id: 'activity', label: __( 'Activity', 'viagent-production' ) },
	{ id: 'settings', label: __( 'Settings', 'viagent-production' ) },
];

function currentTab() {
	const id = window.location.hash.replace( /^#\/?/, '' );
	return TABS.some( ( tab ) => tab.id === id ) ? id : null;
}

function App() {
	const [ tab, setTab ] = useState( currentTab() );
	const [ state, setState ] = useState( null );

	useEffect( () => {
		const onHash = () => setTab( currentTab() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	useEffect( () => {
		api.getState().then( ( result ) => {
			setState( result );
			// First visit: send new users straight to the wizard.
			setTab(
				( current ) =>
					current || ( result.connections ? 'overview' : 'connect' )
			);
		} );
	}, [ tab ] );

	const navigate = ( id ) => {
		window.location.hash = `/${ id }`;
		window.scrollTo( 0, 0 );
	};

	const resume = async () =>
		setState( await api.updateSettings( { paused: false } ) );

	return (
		<div className="viagent-production-app">
			<header className="viagent-production-header">
				<span className="viagent-production-header__logo" aria-hidden="true">
					<svg
						viewBox="0 0 24 24"
						width="22"
						height="22"
						fill="currentColor"
					>
						<path d="M12 2l1.8 4.7L18.5 8.5l-4.7 1.8L12 15l-1.8-4.7L5.5 8.5l4.7-1.8zM18 14l.9 2.1L21 17l-2.1.9L18 20l-.9-2.1L15 17l2.1-.9zM6 15l.7 1.8L8.5 17.5l-1.8.7L6 20l-.7-1.8-1.8-.7 1.8-.7z" />
					</svg>
				</span>
				<div className="viagent-production-header__title">
					<h1>{ __( 'Viagent Production', 'viagent-production' ) }</h1>
					<p>
						{ __(
							'Let AI apps like Claude, ChatGPT and Cursor work on',
							'viagent-production'
						) }{ ' ' }
						<strong>{ settings.siteName }</strong>
					</p>
				</div>
				{ state && tab !== 'overview' && (
					<div
						className={ `viagent-production-status ${ state.paused ? 'is-paused' : 'is-active' }` }
					>
						<span className="viagent-production-status__dot" />
						{ state.paused
							? __( 'AI access paused', 'viagent-production' )
							: __( 'AI access on', 'viagent-production' ) }
						{ state.paused && (
							<Button variant="link" onClick={ resume }>
								{ __( 'Resume', 'viagent-production' ) }
							</Button>
						) }
					</div>
				) }
			</header>

			<nav
				className="viagent-production-nav"
				aria-label={ __( 'Viagent Production sections', 'viagent-production' ) }
			>
				{ TABS.map( ( item ) => (
					<a
						key={ item.id }
						href={ `#/${ item.id }` }
						className={ item.id === tab ? 'is-active' : '' }
						aria-current={ item.id === tab ? 'page' : undefined }
					>
						{ item.label }
						{ item.id === 'connections' &&
							state?.connections > 0 && (
								<span className="viagent-production-nav__count">
									{ state.connections }
								</span>
							) }
					</a>
				) ) }
			</nav>

			<main className="viagent-production-main">
				{ tab === 'overview' && (
					<Overview
						state={ state }
						navigate={ navigate }
						onStateChange={ setState }
					/>
				) }
				{ tab === 'connect' && <Connect navigate={ navigate } /> }
				{ tab === 'connections' && (
					<Connections navigate={ navigate } />
				) }
				{ tab === 'tools' && <Tools /> }
				{ tab === 'activity' && <Activity /> }
				{ tab === 'settings' && (
					<Settings state={ state } onStateChange={ setState } />
				) }
			</main>
		</div>
	);
}

const root = document.getElementById( 'viagent-production-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
