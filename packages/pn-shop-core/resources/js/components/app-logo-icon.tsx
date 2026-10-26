import { SVGAttributes } from 'react';

/** PN Shop's default mark: a shopping bag. Shops replace it with their own logo in a theme. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 42" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M8 13h24a2 2 0 0 1 2 1.84l1.8 23A3 3 0 0 1 32.8 41H7.2a3 3 0 0 1-3-3.16L6 14.84A2 2 0 0 1 8 13ZM12.4 19.2a1.6 1.6 0 1 0 3.2 0a1.6 1.6 0 1 0-3.2 0ZM24.4 19.2a1.6 1.6 0 1 0 3.2 0a1.6 1.6 0 1 0-3.2 0ZM14 13v-3a6 6 0 0 1 12 0v3h-3v-3a3 3 0 0 0-6 0v3Z"
            />
        </svg>
    );
}
